<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Services;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\Contracts\PaymentRepositoryInterface;
use Glueful\Extensions\Payvia\GatewayManager;

final class PaymentService
{
    private ApplicationContext $context;
    public function __construct(
        ApplicationContext $context,
        private PaymentRepositoryInterface $payments,
        private GatewayManager $gateways,
    ) {
        $this->context = $context;
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
        $gatewayKey = $gatewayName ?: (string) config($this->context, 'payvia.default_gateway', 'paystack');

        $options = (array) ($context['options'] ?? []);
        $gateway = $this->gateways->gateway($gatewayKey);
        $verification = $gateway->verify($reference, $options);

        $status = (string) ($verification['status'] ?? 'failed');
        $providerId = (string) ($verification['id'] ?? '');
        $message = (string) ($verification['message'] ?? '');
        $amount = (float) ($verification['amount'] ?? 0.0);
        $currency = (string) ($verification['currency'] ?? 'GHS');

        // Start with caller-provided metadata
        $metadata = [];
        if (isset($context['metadata']) && is_array($context['metadata'])) {
            $metadata = $context['metadata'];
        }

        // Enrich metadata for known gateways when raw payload is available
        if ($gatewayKey === 'paystack' && isset($verification['raw']) && is_array($verification['raw'])) {
            /** @var array<string,mixed> $raw */
            $raw = $verification['raw'];
            $data = (array) ($raw['data'] ?? []);

            $customer = (array) ($data['customer'] ?? []);
            $authorization = (array) ($data['authorization'] ?? []);

            $extra = [];
            if (isset($customer['email']) && is_string($customer['email'])) {
                $extra['customer_email'] = $customer['email'];
            }
            if (isset($authorization['last4']) && is_string($authorization['last4'])) {
                $extra['card_last4'] = $authorization['last4'];
            }
            if (isset($authorization['brand']) && is_string($authorization['brand'])) {
                $extra['card_brand'] = trim($authorization['brand']);
            }
            if (isset($authorization['bank']) && is_string($authorization['bank'])) {
                $extra['card_bank'] = $authorization['bank'];
            }
            if (isset($data['channel']) && is_string($data['channel'])) {
                $extra['channel'] = $data['channel'];
            }

            if ($extra !== []) {
                $metadata = array_merge($metadata, $extra);
            }
        }

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
            'metadata' => $metadata !== [] ? $metadata : null,
            'raw_payload' => config($this->context, 'payvia.features.store_raw_payload', true)
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
