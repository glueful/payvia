<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Extensions\Contracts\Payments\DestinationStatus;
use Glueful\Extensions\Contracts\Payments\PayoutDestination;
use Glueful\Extensions\Contracts\Payments\PayoutRequest;
use Glueful\Extensions\Contracts\Payments\PayoutResult;
use Glueful\Extensions\Contracts\Payments\PayoutStatusResult;
use Glueful\Extensions\Payvia\Gateways\StripeGateway;
use Glueful\Http\Client;
use Glueful\Http\Response\Response as HttpResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\ResponseInterface as SymfonyResponse;

final class StripeTransferTest extends TestCase
{
    private function gateway(Client $http): StripeGateway
    {
        return new StripeGateway($http, $this->context());
    }

    private function context(): ApplicationContext
    {
        $base = sys_get_temp_dir() . '/payvia-stripe-transfer-' . uniqid('', true);
        @mkdir($base . '/config', 0777, true);
        file_put_contents($base . '/config/payvia.php', "<?php\nreturn " . var_export([
            'gateways' => [
                'stripe' => [
                    'secret_key' => 'sk_test_123',
                    'base_url' => 'https://api.stripe.com',
                    'timeout' => 15,
                ],
            ],
        ], true) . ";\n");
        $context = new ApplicationContext($base, 'testing');
        $context->setConfigLoader(new ConfigurationLoader($base, 'testing', $base . '/config'));

        return $context;
    }

    /** @param array<string,mixed> $decoded */
    private function responseOf(int $statusCode, array $decoded): HttpResponse
    {
        $symfony = $this->createMock(SymfonyResponse::class);
        $symfony->method('toArray')->willReturn($decoded);

        $response = $this->createMock(HttpResponse::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getSymfonyResponse')->willReturn($symfony);

        return $response;
    }

    private function destination(): PayoutDestination
    {
        return new PayoutDestination('stripe', 'acct_123');
    }

    private function request(): PayoutRequest
    {
        return new PayoutRequest(5000, 'USD', 'payout_abc:attempt:1');
    }

    public function testTransferMapsSettledTransferToPaid(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('post')->willReturn($this->responseOf(200, [
            'id' => 'tr_paid_1',
            'object' => 'transfer',
            'amount' => 5000,
            'currency' => 'usd',
            'destination' => 'acct_123',
            'balance_transaction' => 'txn_1',
            'reversed' => false,
            'amount_reversed' => 0,
        ]));

        $result = $this->gateway($http)->transfer($this->destination(), $this->request(), 'payout_abc:attempt:1');

        self::assertSame(PayoutResult::PAID, $result['status']);
        self::assertSame('tr_paid_1', $result['provider_ref']);
        self::assertNull($result['failure_code']);
    }

    public function testTransferMapsUnsettledTransferToPending(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('post')->willReturn($this->responseOf(200, [
            'id' => 'tr_pending_1',
            'object' => 'transfer',
            'amount' => 5000,
            'currency' => 'usd',
            'destination' => 'acct_123',
            'balance_transaction' => null,
            'reversed' => false,
            'amount_reversed' => 0,
        ]));

        $result = $this->gateway($http)->transfer($this->destination(), $this->request(), 'payout_abc:attempt:1');

        self::assertSame(PayoutResult::PENDING, $result['status']);
        self::assertSame('tr_pending_1', $result['provider_ref']);
    }

    public function testTransferMapsRateLimitToRetryableFailure(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('post')->willReturn($this->responseOf(429, [
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Too many requests hit the API too quickly.',
            ],
        ]));

        $result = $this->gateway($http)->transfer($this->destination(), $this->request(), 'payout_abc:attempt:1');

        self::assertSame(PayoutResult::RETRYABLE_FAILURE, $result['status']);
        self::assertNotNull($result['failure_code']);
        self::assertNotNull($result['failure_reason']);
    }

    public function testTransferMapsInvalidRecipientToTerminalFailure(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('post')->willReturn($this->responseOf(400, [
            'error' => [
                'type' => 'invalid_request_error',
                'code' => 'resource_missing',
                'param' => 'destination',
                'message' => "No such destination: 'acct_invalid'",
            ],
        ]));

        $result = $this->gateway($http)->transfer($this->destination(), $this->request(), 'payout_abc:attempt:1');

        self::assertSame(PayoutResult::TERMINAL_FAILURE, $result['status']);
        self::assertSame('resource_missing', $result['failure_code']);
    }

    public function testTransferThrowsOnNetworkFailure(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('post')->willThrowException(
            new \Glueful\Http\Exceptions\HttpClientException('Connection timed out')
        );

        $this->expectException(\Glueful\Http\Exceptions\HttpClientException::class);

        $this->gateway($http)->transfer($this->destination(), $this->request(), 'payout_abc:attempt:1');
    }

    public function testTransferThrowsOnServerError(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('post')->willReturn($this->responseOf(500, ['error' => ['message' => 'oops']]));

        $this->expectException(\Throwable::class);

        $this->gateway($http)->transfer($this->destination(), $this->request(), 'payout_abc:attempt:1');
    }

    public function testTransferSendsProviderSafeRefAsIdempotencyKey(): void
    {
        $http = $this->createMock(Client::class);
        $http->expects(self::once())
            ->method('post')
            ->with(
                self::anything(),
                self::callback(function (array $options): bool {
                    return ($options['headers']['Idempotency-Key'] ?? null) === 'payout_abc:attempt:1';
                })
            )
            ->willReturn($this->responseOf(200, [
                'id' => 'tr_1',
                'balance_transaction' => 'txn_1',
                'reversed' => false,
                'amount_reversed' => 0,
            ]));

        $this->gateway($http)->transfer($this->destination(), $this->request(), 'payout_abc:attempt:1');
    }

    public function testTransferStatusMapsPaidWithNoReversal(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('get')->willReturn($this->responseOf(200, [
            'id' => 'tr_1',
            'balance_transaction' => 'txn_1',
            'reversed' => false,
            'amount_reversed' => 0,
        ]));

        $result = $this->gateway($http)->transferStatus('payout_abc:attempt:1', 'tr_1');

        self::assertSame(PayoutStatusResult::PAID, $result['status']);
        self::assertSame(0, $result['reversed_amount']);
    }

    public function testTransferStatusMapsPending(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('get')->willReturn($this->responseOf(200, [
            'id' => 'tr_1',
            'balance_transaction' => null,
            'reversed' => false,
            'amount_reversed' => 0,
        ]));

        $result = $this->gateway($http)->transferStatus('payout_abc:attempt:1', 'tr_1');

        self::assertSame(PayoutStatusResult::PENDING, $result['status']);
    }

    public function testTransferStatusMapsFullReversal(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('get')->willReturn($this->responseOf(200, [
            'id' => 'tr_1',
            'balance_transaction' => 'txn_1',
            'reversed' => true,
            'amount_reversed' => 5000,
        ]));

        $result = $this->gateway($http)->transferStatus('payout_abc:attempt:1', 'tr_1');

        self::assertSame(PayoutStatusResult::REVERSED, $result['status']);
        self::assertSame(5000, $result['reversed_amount']);
    }

    public function testTransferStatusMapsPartialReversalAsPaid(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('get')->willReturn($this->responseOf(200, [
            'id' => 'tr_1',
            'balance_transaction' => 'txn_1',
            'reversed' => false,
            'amount_reversed' => 1500,
        ]));

        $result = $this->gateway($http)->transferStatus('payout_abc:attempt:1', 'tr_1');

        self::assertSame(PayoutStatusResult::PAID, $result['status']);
        self::assertSame(1500, $result['reversed_amount']);
    }

    public function testTransferStatusThrowsWithoutProviderRef(): void
    {
        $http = $this->createMock(Client::class);

        $this->expectException(\RuntimeException::class);

        $this->gateway($http)->transferStatus('payout_abc:attempt:1', null);
    }

    public function testInspectAccountMapsReady(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('get')->willReturn($this->responseOf(200, [
            'id' => 'acct_123',
            'payouts_enabled' => true,
            'requirements' => ['disabled_reason' => null],
        ]));

        $result = $this->gateway($http)->inspectAccount('acct_123');

        self::assertSame(DestinationStatus::READY, $result['state']);
    }

    public function testInspectAccountMapsPending(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('get')->willReturn($this->responseOf(200, [
            'id' => 'acct_123',
            'payouts_enabled' => false,
            'requirements' => ['disabled_reason' => null, 'currently_due' => ['individual.dob.day']],
        ]));

        $result = $this->gateway($http)->inspectAccount('acct_123');

        self::assertSame(DestinationStatus::PENDING, $result['state']);
    }

    public function testInspectAccountMapsRestricted(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('get')->willReturn($this->responseOf(200, [
            'id' => 'acct_123',
            'payouts_enabled' => false,
            'requirements' => ['disabled_reason' => 'rejected.fraud'],
        ]));

        $result = $this->gateway($http)->inspectAccount('acct_123');

        self::assertSame(DestinationStatus::RESTRICTED, $result['state']);
        self::assertSame('rejected.fraud', $result['failure_code']);
    }
}
