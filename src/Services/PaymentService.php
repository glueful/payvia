<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Services;

use Glueful\Extensions\Payvia\Contracts\PaymentRepositoryInterface;
use Glueful\Extensions\Payvia\GatewayManager;

final class PaymentService
{
    public function __construct(
        private PaymentRepositoryInterface $payments,
        private GatewayManager $gateways,
    ) {
    }

    /**
     * @param array<string,mixed> $context user_uuid, payable_type, payable_id, metadata, options
     * @return array<string,mixed>
     */
    public function confirmAndRecord(
        string $reference,
        ?string $gatewayName = null,
        array $context = []
    ): array {
        $gatewayKey = $gatewayName ?: (string) config('payvia.default_gateway', 'paystack');

        $options = (array) ($context['options'] ?? []);
        $gateway = $this->gateways->gateway($gatewayKey);
        $verification = $gateway->verify($reference, $options);

        $status = (string) ($verification['status'] ?? 'failed');
        $providerId = (string) ($verification['id'] ?? '');
        $message = (string) ($verification['message'] ?? '');
        $amount = (float) ($verification['amount'] ?? 0.0);
        $currency = (string) ($verification['currency'] ?? 'GHS');

        $payload = [
            'user_uuid' => $context['user_uuid'] ?? null,
            'payable_type' => $context['payable_type'] ?? null,
            'payable_id' => $context['payable_id'] ?? null,
            'gateway' => $gatewayKey,
            'gateway_transaction_id' => $providerId !== '' ? $providerId : null,
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $status,
            'message' => $message !== '' ? $message : null,
            'metadata' => $context['metadata'] ?? null,
            'raw_payload' => config('payvia.features.store_raw_payload', true)
                ? json_encode($verification, JSON_THROW_ON_ERROR)
                : null,
        ];

        $existing = $this->payments->findByReference($reference);
        if ($existing === null) {
            $this->payments->createPayment($payload);
        } else {
            $this->payments->updateByReference($reference, $payload);
        }

        return [
            'payment_status' => $status,
            'gateway' => $gatewayKey,
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
            'message' => $message !== '' ? $message : null,
            'verification' => $verification,
        ];
    }
}
