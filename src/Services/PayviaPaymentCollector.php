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
use Glueful\Extensions\Payvia\Contracts\ResumableHostedSessionGateway;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\DuplicateReferenceException;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;
use Glueful\Helpers\Utils;

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

        // Cooldown: a repeat initiate() — a shopper clicking "pay" again, a retried checkout, an
        // abusive client — must not mean a provider round trip per click. That is a self-inflicted
        // rate limit, and a provider 429 comes back as UNKNOWN, i.e. fail-closed for every shopper
        // at once. A recent CONFIRMED-LIVE observation is therefore trusted for
        // `payvia.session_liveness_cooldown_seconds`. Only a confirmed-live probe ever writes that
        // stamp, so a dead/unknown answer can never buy itself a quiet period, and a brand-new
        // attempt has no stamp at all.
        if ($this->withinLivenessCooldown($context, $existing)) {
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

        if ($state === HostedSessionStateCapableGateway::STATE_LIVE) {
            $this->intents->recordLivenessConfirmation($context, (string) $existing['uuid'], time());

            return $this->fromIntent($existing);
        }

        if ($state === HostedSessionStateCapableGateway::STATE_COMPLETED) {
            // Never replaced, but never cooled down either: a completed session is terminal and
            // its intent is about to be closed by settlement, so there is nothing to keep warm.
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
     * that same row — same attempt uuid, same provider key.
     *
     * What "recovers" MEANS is driver-specific, and getting it wrong wedges the payable forever.
     * Stripe's Idempotency-Key makes replaying the create the documented recovery (it replays the
     * original session). Paystack's reference is a permanent uniqueness constraint, not an
     * idempotency key: replaying returns HTTP 400 "Duplicate Transaction Reference" every time,
     * for good. So whenever this method RESUMES an attempt some earlier call already claimed, a
     * {@see ResumableHostedSessionGateway} is asked what actually happened before anything is
     * created. Fresh attempts never go through that path.
     *
     * @param bool $allowReplacement false on the single recursive re-entry, so a driver that
     *                               keeps answering "replace" can never loop
     */
    private function openAttempt(
        ApplicationContext $context,
        PayableReference $payable,
        string $gatewayKey,
        InitiationCapableGateway $gateway,
        bool $allowReplacement = true,
    ): PaymentInitiation {
        // The uuid is minted HERE so the outcome is legible: claimAttempt() returns this exact
        // row when it inserted, and a DIFFERENT row when it recovered one an earlier call had
        // already claimed. That distinction is what makes resumption detectable at all.
        $intendedUuid = Utils::generateNanoID();
        $claim = $this->intents->claimAttempt($context, [
            'uuid' => $intendedUuid,
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
        $resumed = $attemptUuid !== $intendedUuid;

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

        $result = null;
        if ($resumed && $gateway instanceof ResumableHostedSessionGateway) {
            try {
                $resume = $gateway->resumeHostedSession($payable, $options);
            } catch (\Throwable $e) {
                // Indeterminate: the attempt row stays exactly as it is (still holding the port,
                // still resumable) so a later call can ask again.
                throw ProviderSessionStateUnknownException::for(
                    $gatewayKey,
                    $payable->type,
                    $payable->id,
                    (string) ($claim['reference'] ?? ''),
                    $e
                );
            }

            // Read as a plain string on purpose: the contract narrows this to three values, but a
            // third-party driver is not a type checker, and the fall-through here is the
            // dangerous direction (re-creating under a reference the provider may already hold).
            // Anything unrecognized is therefore fail-closed below, not silently "absent".
            /** @var string $outcome */
            $outcome = $resume['outcome'];

            if (
                $outcome !== ResumableHostedSessionGateway::RESUME_ABSENT
                && $outcome !== ResumableHostedSessionGateway::RESUME_ADOPT
                && $outcome !== ResumableHostedSessionGateway::RESUME_REPLACE
            ) {
                throw ProviderSessionStateUnknownException::for(
                    $gatewayKey,
                    $payable->type,
                    $payable->id,
                    (string) ($claim['reference'] ?? '')
                );
            }

            if ($outcome === ResumableHostedSessionGateway::RESUME_REPLACE) {
                if (!$allowReplacement) {
                    throw ProviderSessionStateUnknownException::for(
                        $gatewayKey,
                        $payable->type,
                        $payable->id,
                        (string) ($claim['reference'] ?? '')
                    );
                }

                // This attempt can never yield a usable checkout, and the driver has confirmed no
                // url of its was ever exposed. Free the port and start a genuinely new attempt —
                // new uuid, therefore a new provider reference.
                $this->intents->fail($context, $attemptUuid);

                return $this->openAttempt($context, $payable, $gatewayKey, $gateway, false);
            }

            if ($outcome === ResumableHostedSessionGateway::RESUME_ADOPT) {
                // A session already exists under this attempt: adopt it rather than create a
                // second one. It may carry no checkout url (e.g. Paystack, whose verify response
                // cannot return one) — that is honest, and the caller treats a missing url as an
                // unavailable checkout rather than being handed a fabricated one.
                $result = is_array($resume['session'] ?? null) ? $resume['session'] : [];
            }
        }

        $result ??= $gateway->initialize($payable, $options);
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
     * True when this open attempt's liveness was PROVIDER-CONFIRMED recently enough to trust
     * without asking again. `payvia.session_liveness_cooldown_seconds` defaults to 30; 0 (or
     * negative) disables the cooldown entirely and every call probes.
     *
     * @param array<string,mixed> $intent
     */
    private function withinLivenessCooldown(ApplicationContext $context, array $intent): bool
    {
        $cooldown = (int) config($context, 'payvia.session_liveness_cooldown_seconds', 30);
        if ($cooldown <= 0) {
            return false;
        }

        $payload = is_array($intent['payload'] ?? null) ? $intent['payload'] : [];
        $confirmedAt = $payload[PaymentIntentRepository::LIVENESS_CONFIRMED_AT] ?? null;
        if (!is_numeric($confirmedAt)) {
            return false;
        }

        $age = time() - (int) $confirmedAt;

        // A future-dated stamp (clock skew between app servers) is not evidence of anything.
        return $age >= 0 && $age < $cooldown;
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
