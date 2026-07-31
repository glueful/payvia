<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Services;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Payments\PaymentInitiation;
use Glueful\Extensions\Payvia\Support\PayviaSettings;
use Glueful\Extensions\Payvia\Contracts\InitiationCapableGateway;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;

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
        if ($existing !== null) {
            return $this->fromIntent($existing);
        }

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
            return new PaymentInitiation('payvia', 'manual', [
                'instructions' => 'Payment is collected manually; an operator will mark this order paid. '
                    . "(Gateway '{$gatewayKey}' is not configured.)",
            ]);
        }

        $gateway = $this->gateways->gateway($gatewayKey);

        if (!$gateway instanceof InitiationCapableGateway) {
            return new PaymentInitiation('payvia', 'manual', [
                'instructions' => "Gateway '{$gatewayKey}' does not support hosted initiation; confirm via reference.",
            ]);
        }

        // The payable-type-agnostic initiation seam: whoever BUILDS a payable supplies the
        // well-known metadata keys (email, callback_url, cancel_url) — orders, subscriptions,
        // invoices alike — and this ONE lift hands them to the gateway as options. Never add
        // per-consumer parameters or payable_type special-casing here. Initiation exceptions
        // deliberately PROPAGATE: mapping failures (e.g. to init_failed) is the caller's job.
        $options = array_filter([
            'email' => $payable->metadata['email'] ?? null,
            'callback_url' => $payable->metadata['callback_url'] ?? null,
            'cancel_url' => $payable->metadata['cancel_url'] ?? null,
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');

        $result = $gateway->initialize($payable, $options);
        $created = $this->intents->createOpen($context, [
            'payable_type' => $payable->type,
            'payable_id' => $payable->id,
            'gateway' => $gatewayKey,
            'reference' => (string) $result['reference'],
            'amount' => $payable->amount,
            'currency' => $payable->currency,
            'payload' => $result,
        ]);

        if (!$created) {
            $winner = $this->intents->findOpen($context, $payable->type, $payable->id);
            if ($winner !== null) {
                return $this->fromIntent($winner);
            }
        }

        return new PaymentInitiation('payvia', 'ok', [
            'reference' => (string) $result['reference'],
            'checkout_url' => $result['checkout_url'] ?? null,
            'gateway' => $gatewayKey,
        ]);
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
