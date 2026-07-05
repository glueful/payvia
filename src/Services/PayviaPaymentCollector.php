<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Services;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Payments\PaymentInitiation;
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

        $gatewayKey = (string) config($context, 'payvia.default_gateway', 'paystack');
        $gateway = $this->gateways->gateway($gatewayKey);

        if (!$gateway instanceof InitiationCapableGateway) {
            return new PaymentInitiation('payvia', 'manual', [
                'instructions' => "Gateway '{$gatewayKey}' does not support hosted initiation; confirm via reference.",
            ]);
        }

        $result = $gateway->initialize($payable);
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
