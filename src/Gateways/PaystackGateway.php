<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Gateways;

use Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface;
use Glueful\Http\Client as HttpClient;

final class PaystackGateway implements PaymentGatewayInterface
{
    public function __construct(
        private HttpClient $httpClient,
    ) {
    }

    public function verify(string $reference, array $options = []): array
    {
        $config = (array) config('payvia.gateways.paystack', []);

        $secret = (string) ($config['secret_key'] ?? '');
        if ($secret === '') {
            return [
                'status' => 'failed',
                'reference' => $reference,
                'message' => 'Missing Paystack secret key (PAYVIA_PAYSTACK_SECRET_KEY / PAYSTACK_SECRET_KEY)',
            ];
        }

        $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.paystack.co'), '/');
        $timeout = (int) ($config['timeout'] ?? 15);

        $verifyUrl = (string) ($options['verify_url'] ?? ($baseUrl . '/transaction/verify/' . rawurlencode($reference)));

        try {
            $response = $this->httpClient->get($verifyUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $secret,
                    'Accept' => 'application/json',
                ],
                'timeout' => $timeout,
            ]);

            $httpCode = $response->getStatusCode();
            /** @var array<string,mixed> $decoded */
            $decoded = $response->toArray();
        } catch (\Throwable $e) {
            return [
                'status' => 'failed',
                'reference' => $reference,
                'message' => 'Paystack verification request failed: ' . $e->getMessage(),
            ];
        }

        $apiStatus = (bool) ($decoded['status'] ?? false);
        $data = (array) ($decoded['data'] ?? []);

        // Prefer gateway_response from transaction data when available
        $rawMessage = (string) ($decoded['message'] ?? '');
        $gatewayResponse = isset($data['gateway_response']) ? (string) $data['gateway_response'] : '';
        $message = $gatewayResponse !== '' ? $gatewayResponse : $rawMessage;

        if (!$apiStatus || $httpCode < 200 || $httpCode >= 300) {
            return [
                'status' => 'failed',
                'reference' => (string) ($data['reference'] ?? $reference),
                'message' => $message !== '' ? $message : 'Paystack verification returned error',
                'raw' => $decoded,
            ];
        }

        $amount = isset($data['amount']) ? ((float) $data['amount'] / 100.0) : 0.0;
        $currency = (string) ($data['currency'] ?? 'GHS');

        return [
            'status' => (string) ($data['status'] ?? 'success'),
            'id' => $data['id'] ?? null,
            'reference' => (string) ($data['reference'] ?? $reference),
            'amount' => $amount,
            'currency' => $currency,
            'message' => $message,
            'raw' => $decoded,
        ];
    }
}
