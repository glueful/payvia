<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Services;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Payments\PaymentInitiation;
use Glueful\Extensions\Payvia\Support\PayviaSettings;
use Glueful\Extensions\Payvia\Contracts\HostedSessionRenewalCapableGateway;
use Glueful\Extensions\Payvia\Contracts\HostedSessionStateCapableGateway;
use Glueful\Extensions\Payvia\Contracts\InitiationCapableGateway;
use Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\DuplicateReferenceException;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;

/**
 * ENSURE-LIVE hosted collection (payment-links spec §2.1 / Ruling 5).
 *
 * `initiate()` guarantees ONE provably live hosted session per payable — never an unconditional
 * fresh one:
 *
 *   no intent           -> claim an attempt, THEN create a session, then open the intent
 *   confirmed live      -> hand back the SAME url; the provider is never asked to make a second
 *   confirmed completed -> likewise; settlement is the webhook's business, not initiation's
 *   confirmed dead      -> supersede that attempt (its reference stays webhook-addressable) and
 *                          claim a NEW one, with a new provider idempotency key/reference
 *   unknown             -> typed, fail-CLOSED refusal; the existing intent is left untouched
 *   renewal impossible  -> typed, fail-CLOSED refusal (Paystack, Ruling 6)
 *
 * Two invariants worth stating out loud, because both are easy to break by accident:
 *
 *  1. Every provider call in here — creation, liveness probe, expire/re-fetch — runs OUTSIDE any
 *     database transaction and while holding no row locks. The repository's writes are
 *     single-statement compare-and-swaps precisely so this stays true; a slow or hanging provider
 *     must never be able to block a concurrent revoke, sweep, or settlement.
 *  2. Serialization is the database's job, not a mutex here. `initializing` and `open` rows share
 *     the payable's active idempotency port, so `UNIQUE(tenant_uuid, idempotency_key)` permits at
 *     most one live attempt per payable; whoever loses that race recovers the winner's attempt
 *     (same attempt uuid ⇒ same provider session) instead of minting a competing checkout.
 */
final class PayviaPaymentCollector implements PaymentCollector
{
    public function __construct(
        private GatewayManager $gateways,
        private PaymentIntentRepository $intents,
    ) {
    }

    public function initiate(ApplicationContext $context, PayableReference $payable): PaymentInitiation
    {
        $existing = $this->intents->findOpen($context, $payable->type, $payable->id);
        $gatewayKey = PayviaSettings::defaultGateway($context);

        // Graceful degradation: a disabled default gateway — or one that DECLARES a secret_key
        // slot (paystack/stripe always do, via their env defaults) but has no value in it — can
        // never initiate a real charge (an empty secret is a guaranteed decline). Fall back to
        // manual collection instead of failing checkout, so installing payvia BEFORE entering
        // keys never breaks a store; the moment a key lands (env or a host settings screen),
        // initiation takes over. Drivers that genuinely need no secret simply omit the slot.
        $config = PayviaSettings::gatewayConfig($context, $gatewayKey);
        $secret = $config['secret_key'] ?? null;
        $keyless = array_key_exists('secret_key', $config) && (!is_string($secret) || trim($secret) === '');
        if (($config['enabled'] ?? true) === false || $keyless) {
            return $existing !== null ? $this->fromIntent($existing) : new PaymentInitiation('payvia', 'manual', [
                'instructions' => 'Payment is collected manually; an operator will mark this order paid. '
                    . "(Gateway '{$gatewayKey}' is not configured.)",
            ]);
        }

        $gateway = $this->resolveGateway($gatewayKey, $existing !== null);

        if (!$gateway instanceof InitiationCapableGateway) {
            // An existing open intent is still the truth for this payable even if the CURRENT
            // default gateway can no longer initiate: never replace what we cannot probe.
            return $existing !== null ? $this->fromIntent($existing) : new PaymentInitiation('payvia', 'manual', [
                'instructions' => "Gateway '{$gatewayKey}' does not support hosted initiation; confirm via reference.",
            ]);
        }

        if ($existing !== null) {
            return $this->ensureLive($context, $payable, $gatewayKey, $gateway, $existing);
        }

        return $this->openAttempt($context, $payable, $gatewayKey, $gateway);
    }

    /**
     * The ensure-live decision for a payable that ALREADY has an open intent. Everything here is
     * biased towards keeping what exists: the only branch that retires the old attempt is a
     * provider-PROVEN death.
     *
     * @param array<string,mixed> $existing
     */
    private function ensureLive(
        ApplicationContext $context,
        PayableReference $payable,
        string $gatewayKey,
        InitiationCapableGateway $gateway,
        array $existing,
    ): PaymentInitiation {
        $reference = isset($existing['reference']) ? (string) $existing['reference'] : '';

        // Nothing provable ⇒ nothing replaceable. A driver with no liveness contract, an intent
        // opened under a DIFFERENT gateway than the one now configured, or a row with no
        // reference to ask about all keep exactly the behaviour Payvia has always had: return
        // the open intent as-is.
        if (
            $reference === ''
            || (string) $existing['gateway'] !== $gatewayKey
            || !$gateway instanceof HostedSessionStateCapableGateway
        ) {
            return $this->fromIntent($existing);
        }

        try {
            $state = $gateway->hostedSessionState($reference);
        } catch (\Throwable $e) {
            // An unreachable/erroring provider is an UNKNOWN state, never a dead one.
            throw ProviderSessionStateUnknownException::for(
                $gatewayKey,
                $payable->type,
                $payable->id,
                $reference,
                $e
            );
        }

        if (
            $state === HostedSessionStateCapableGateway::STATE_LIVE
            || $state === HostedSessionStateCapableGateway::STATE_COMPLETED
        ) {
            return $this->fromIntent($existing);
        }

        if ($state !== HostedSessionStateCapableGateway::STATE_DEAD) {
            throw ProviderSessionStateUnknownException::for($gatewayKey, $payable->type, $payable->id, $reference);
        }

        // The session LOOKS dead. That is still only a read: replacing it requires proof from a
        // driver that can actually produce proof.
        if (!$gateway instanceof HostedSessionRenewalCapableGateway) {
            throw SessionRenewalUnavailableException::for($gatewayKey, $payable->type, $payable->id, $reference);
        }

        try {
            $proof = $gateway->abandonHostedSession($reference);
        } catch (\Throwable $e) {
            throw ProviderSessionStateUnknownException::for(
                $gatewayKey,
                $payable->type,
                $payable->id,
                $reference,
                $e
            );
        }

        if ($proof === HostedSessionRenewalCapableGateway::RENEWAL_STILL_LIVE) {
            // The expire/re-fetch round trip is the authority and it contradicted the probe:
            // the session survived (or had already completed). Keep it.
            return $this->fromIntent($existing);
        }

        if ($proof !== HostedSessionRenewalCapableGateway::RENEWAL_CONFIRMED_DEAD) {
            throw ProviderSessionStateUnknownException::for($gatewayKey, $payable->type, $payable->id, $reference);
        }

        // Proven dead: retire the attempt (preserved as `superseded`, so its provider reference
        // stays addressable for any late webhook) and free the payable's active port. A losing
        // concurrent renewal simply finds this already done and recovers the successor below.
        $this->intents->supersede($context, (string) $existing['uuid']);

        return $this->openAttempt($context, $payable, $gatewayKey, $gateway);
    }

    /**
     * Claim an attempt, create the provider session under it, and open the intent.
     *
     * The claim happens BEFORE any provider I/O and its uuid IS the attempt identity: the driver
     * derives its idempotency key (Stripe) or transaction reference (Paystack) from it. So a
     * transport timeout leaves an `initializing` row holding the port, and the next call recovers
     * that same row — same attempt uuid, same provider key, same session — instead of creating a
     * second checkout for the payable.
     */
    private function openAttempt(
        ApplicationContext $context,
        PayableReference $payable,
        string $gatewayKey,
        InitiationCapableGateway $gateway,
    ): PaymentInitiation {
        $claim = $this->intents->claimAttempt($context, [
            'payable_type' => $payable->type,
            'payable_id' => $payable->id,
            'gateway' => $gatewayKey,
            'amount' => $payable->amount,
            'currency' => $payable->currency,
        ]);

        // claimAttempt() may hand back an ALREADY-OPEN row (a concurrent caller finished its
        // provider round trip first) — that attempt is live, so there is nothing left to do.
        if ((string) $claim['status'] === PaymentIntentRepository::STATUS_OPEN) {
            return $this->fromIntent($claim);
        }

        $attemptUuid = (string) $claim['uuid'];

        // The payable-type-agnostic initiation seam: whoever BUILDS a payable supplies the
        // well-known metadata keys (email, callback_url, cancel_url) — orders, subscriptions,
        // invoices alike — and this ONE lift hands them to the gateway as options. Never add
        // per-consumer parameters or payable_type special-casing here. The attempt uuid is the
        // single option the collector itself owns. Initiation exceptions deliberately PROPAGATE:
        // mapping failures (e.g. to init_failed) is the caller's job, and an unknown outcome must
        // leave the claimed row `initializing` for a same-attempt retry.
        $options = array_filter([
            'email' => $payable->metadata['email'] ?? null,
            'callback_url' => $payable->metadata['callback_url'] ?? null,
            'cancel_url' => $payable->metadata['cancel_url'] ?? null,
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');
        $options['attempt_uuid'] = $attemptUuid;

        $result = $gateway->initialize($payable, $options);
        $reference = isset($result['reference']) ? (string) $result['reference'] : '';
        if ($reference === '') {
            throw new \RuntimeException(
                "Gateway '{$gatewayKey}' returned no reference for {$payable->type}:{$payable->id}."
            );
        }

        try {
            $opened = $this->intents->markOpen($context, $attemptUuid, $reference, $result);
        } catch (DuplicateReferenceException $e) {
            // A CLASSIFIED deterministic rejection (this gateway handed back a reference already
            // owned by a different, retired attempt), not an unknown outcome: retrying the same
            // attempt would collide identically forever, so free the port before surfacing it.
            $this->intents->fail($context, $attemptUuid);

            throw $e;
        }

        if (!$opened) {
            // The CAS found the row no longer `initializing` — a concurrent caller opened this
            // very attempt (same uuid, same provider session) or retired it. Whatever is open now
            // is the answer.
            $winner = $this->intents->findOpen($context, $payable->type, $payable->id);
            if ($winner !== null) {
                return $this->fromIntent($winner);
            }

            // Nothing open, and our own attempt row is gone: a real provider session now exists
            // that NOTHING records. Reporting 'ok' here would hand a customer a live checkout url
            // whose eventual settlement webhook has no intent to attribute — the exact
            // unpersisted-success failure Task 1's typed write outcomes exist to prevent. Fail.
            throw new \RuntimeException(sprintf(
                "Payvia opened a '%s' session (%s) for %s:%s but its attempt row was retired "
                . 'concurrently; refusing to report an unpersisted success.',
                $gatewayKey,
                $reference,
                $payable->type,
                $payable->id,
            ));
        }

        return new PaymentInitiation('payvia', 'ok', [
            'reference' => $reference,
            'checkout_url' => $result['checkout_url'] ?? null,
            'gateway' => $gatewayKey,
        ]);
    }

    /**
     * Resolving the driver must not, by itself, be able to turn an existing open intent into a
     * failed checkout: a gateway id that no longer resolves (renamed driver, removed
     * registration) is exactly the "cannot probe" case, and the caller still gets its intent.
     */
    private function resolveGateway(string $gatewayKey, bool $haveExistingIntent): ?PaymentGatewayInterface
    {
        if (!$haveExistingIntent) {
            return $this->gateways->gateway($gatewayKey);
        }

        try {
            return $this->gateways->gateway($gatewayKey);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $intent */
    private function fromIntent(array $intent): PaymentInitiation
    {
        $payload = is_array($intent['payload'] ?? null) ? $intent['payload'] : [];

        return new PaymentInitiation('payvia', 'ok', [
            'reference' => (string) $intent['reference'],
            'checkout_url' => $payload['checkout_url'] ?? null,
            'gateway' => (string) $intent['gateway'],
        ]);
    }
}
