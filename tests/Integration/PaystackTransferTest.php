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
use Glueful\Extensions\Payvia\Gateways\PaystackGateway;
use Glueful\Http\Client;
use Glueful\Http\Response\Response as HttpResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\ResponseInterface as SymfonyResponse;

final class PaystackTransferTest extends TestCase
{
    private function gateway(Client $http): PaystackGateway
    {
        return new PaystackGateway($http, $this->context());
    }

    private function context(): ApplicationContext
    {
        $base = sys_get_temp_dir() . '/payvia-paystack-transfer-' . uniqid('', true);
        @mkdir($base . '/config', 0777, true);
        file_put_contents($base . '/config/payvia.php', "<?php\nreturn " . var_export([
            'gateways' => [
                'paystack' => [
                    'secret_key' => 'sk_test_123',
                    'base_url' => 'https://api.paystack.co',
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
        return new PayoutDestination('paystack', 'RCP_123');
    }

    private function request(): PayoutRequest
    {
        return new PayoutRequest(5000, 'GHS', 'payout_abc:attempt:1');
    }

    public function testTransferMapsSettledSuccessToPaid(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('post')->willReturn($this->responseOf(200, [
            'status' => true,
            'message' => 'Transfer successful',
            'data' => [
                'reference' => 'py_ref_1',
                'transfer_code' => 'TRF_1',
                'status' => 'success',
                'amount' => 5000,
                'transferred_at' => '2024-01-01T00:00:00.000Z',
            ],
        ]));

        $result = $this->gateway($http)->transfer($this->destination(), $this->request(), 'py_ref_1');

        self::assertSame(PayoutResult::PAID, $result['status']);
        self::assertSame('TRF_1', $result['provider_ref']);
    }

    public function testTransferMapsQueuedSuccessWithNullTransferredAtToPending(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('post')->willReturn($this->responseOf(200, [
            'status' => true,
            'message' => 'Transfer has been queued',
            'data' => [
                'reference' => 'py_ref_1',
                'transfer_code' => 'TRF_1',
                'status' => 'success',
                'amount' => 5000,
                'transferred_at' => null,
            ],
        ]));

        $result = $this->gateway($http)->transfer($this->destination(), $this->request(), 'py_ref_1');

        self::assertSame(PayoutResult::PENDING, $result['status']);
    }

    public function testTransferMapsOtpToPendingWithActionRequired(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('post')->willReturn($this->responseOf(200, [
            'status' => true,
            'message' => 'Transfer requires OTP to continue',
            'data' => [
                'reference' => 'py_ref_1',
                'transfer_code' => 'TRF_1',
                'status' => 'otp',
                'amount' => 5000,
            ],
        ]));

        $result = $this->gateway($http)->transfer($this->destination(), $this->request(), 'py_ref_1');

        // Not terminal: the hold must be retained until a provider-confirmed
        // success/failure -- MV4 ships no OTP-entry workflow.
        self::assertSame(PayoutResult::PENDING, $result['status']);
        self::assertSame('action_required', $result['failure_code']);
    }

    public function testTransferMapsPendingStatusToPending(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('post')->willReturn($this->responseOf(200, [
            'status' => true,
            'message' => 'Transfer is pending',
            'data' => [
                'reference' => 'py_ref_1',
                'transfer_code' => 'TRF_1',
                'status' => 'pending',
                'amount' => 5000,
            ],
        ]));

        $result = $this->gateway($http)->transfer($this->destination(), $this->request(), 'py_ref_1');

        self::assertSame(PayoutResult::PENDING, $result['status']);
    }

    public function testTransferMapsRateLimitToRetryableFailure(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('post')->willReturn($this->responseOf(429, [
            'status' => false,
            'message' => 'Too many requests',
        ]));

        $result = $this->gateway($http)->transfer($this->destination(), $this->request(), 'py_ref_1');

        self::assertSame(PayoutResult::RETRYABLE_FAILURE, $result['status']);
    }

    public function testTransferMapsInvalidRecipientToTerminalFailure(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('post')->willReturn($this->responseOf(404, [
            'status' => false,
            'message' => 'Recipient not found',
        ]));

        $result = $this->gateway($http)->transfer($this->destination(), $this->request(), 'py_ref_1');

        self::assertSame(PayoutResult::TERMINAL_FAILURE, $result['status']);
        self::assertNotNull($result['failure_reason']);
    }

    public function testTransferThrowsOnNetworkFailure(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('post')->willThrowException(
            new \Glueful\Http\Exceptions\HttpClientException('Connection timed out')
        );

        $this->expectException(\Glueful\Http\Exceptions\HttpClientException::class);

        $this->gateway($http)->transfer($this->destination(), $this->request(), 'py_ref_1');
    }

    public function testTransferThrowsOnServerError(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('post')->willReturn($this->responseOf(500, ['status' => false, 'message' => 'oops']));

        $this->expectException(\Throwable::class);

        $this->gateway($http)->transfer($this->destination(), $this->request(), 'py_ref_1');
    }

    public function testTransferSendsProviderSafeRefAsReference(): void
    {
        $http = $this->createMock(Client::class);
        $http->expects(self::once())
            ->method('post')
            ->with(
                self::anything(),
                self::callback(function (array $options): bool {
                    return ($options['json']['reference'] ?? null) === 'py_ref_1';
                })
            )
            ->willReturn($this->responseOf(200, [
                'status' => true,
                'message' => 'ok',
                'data' => [
                    'reference' => 'py_ref_1',
                    'transfer_code' => 'TRF_1',
                    'status' => 'success',
                    'transferred_at' => '2024-01-01T00:00:00.000Z',
                ],
            ]));

        $this->gateway($http)->transfer($this->destination(), $this->request(), 'py_ref_1');
    }

    public function testRecoverTransferNeverReplaysTheCreate(): void
    {
        $http = $this->createMock(Client::class);
        $http->expects(self::never())->method('post');
        $http->expects(self::once())
            ->method('get')
            ->with(self::stringContains('/transfer/verify/py_ref_1'))
            ->willReturn($this->responseOf(200, [
                'status' => true,
                'message' => 'ok',
                'data' => [
                    'reference' => 'py_ref_1',
                    'transfer_code' => 'TRF_1',
                    'status' => 'success',
                    'amount' => 5000,
                    'transferred_at' => '2024-01-01T00:00:00.000Z',
                ],
            ]));

        $result = $this->gateway($http)->recoverTransfer($this->destination(), $this->request(), 'py_ref_1', null);

        self::assertSame(PayoutResult::PAID, $result['status']);
        self::assertSame('TRF_1', $result['provider_ref']);
    }

    public function testRecoverTransferDownMapsReversedVerifyToPaid(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('get')->willReturn($this->responseOf(200, [
            'status' => true,
            'message' => 'ok',
            'data' => [
                'reference' => 'py_ref_1',
                'transfer_code' => 'TRF_1',
                'status' => 'reversed',
                'amount' => 5000,
            ],
        ]));

        $result = $this->gateway($http)->recoverTransfer($this->destination(), $this->request(), 'py_ref_1', null);

        // recoverTransfer() speaks transfer()'s five-state PayoutResult
        // vocabulary -- REVERSED only exists in transferStatus()'s six-state
        // shape, so a reversed verify still classifies as PAID here (a
        // later status() call reports the reversal).
        self::assertSame(PayoutResult::PAID, $result['status']);
        self::assertSame('TRF_1', $result['provider_ref']);
    }

    public function testRecoverTransferDownMapsOtpVerifyToPendingWithActionRequired(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('get')->willReturn($this->responseOf(200, [
            'status' => true,
            'message' => 'Transfer requires OTP to continue',
            'data' => [
                'reference' => 'py_ref_1',
                'transfer_code' => 'TRF_1',
                'status' => 'otp',
                'amount' => 5000,
            ],
        ]));

        $result = $this->gateway($http)->recoverTransfer($this->destination(), $this->request(), 'py_ref_1', null);

        self::assertSame(PayoutResult::PENDING, $result['status']);
        self::assertSame('action_required', $result['failure_code']);
    }

    public function testRecoverTransferDownMapsFailedVerifyToTerminalFailure(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('get')->willReturn($this->responseOf(200, [
            'status' => true,
            'message' => 'ok',
            'data' => [
                'reference' => 'py_ref_1',
                'transfer_code' => 'TRF_1',
                'status' => 'failed',
                'amount' => 5000,
            ],
        ]));

        $result = $this->gateway($http)->recoverTransfer($this->destination(), $this->request(), 'py_ref_1', null);

        self::assertSame(PayoutResult::TERMINAL_FAILURE, $result['status']);
    }

    public function testTransferStatusMapsPaid(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('get')->willReturn($this->responseOf(200, [
            'status' => true,
            'message' => 'ok',
            'data' => [
                'reference' => 'py_ref_1',
                'transfer_code' => 'TRF_1',
                'status' => 'success',
                'amount' => 5000,
                'transferred_at' => '2024-01-01T00:00:00.000Z',
            ],
        ]));

        $result = $this->gateway($http)->transferStatus('py_ref_1', 'TRF_1');

        self::assertSame(PayoutStatusResult::PAID, $result['status']);
        self::assertSame(0, $result['reversed_amount']);
    }

    public function testTransferStatusMapsPending(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('get')->willReturn($this->responseOf(200, [
            'status' => true,
            'message' => 'ok',
            'data' => [
                'reference' => 'py_ref_1',
                'transfer_code' => 'TRF_1',
                'status' => 'pending',
                'amount' => 5000,
            ],
        ]));

        $result = $this->gateway($http)->transferStatus('py_ref_1', 'TRF_1');

        self::assertSame(PayoutStatusResult::PENDING, $result['status']);
    }

    public function testTransferStatusMapsOtpToPendingWithActionRequired(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('get')->willReturn($this->responseOf(200, [
            'status' => true,
            'message' => 'Transfer requires OTP to continue',
            'data' => [
                'reference' => 'py_ref_1',
                'transfer_code' => 'TRF_1',
                'status' => 'otp',
                'amount' => 5000,
            ],
        ]));

        $result = $this->gateway($http)->transferStatus('py_ref_1', 'TRF_1');

        // Reconcile path must preserve the hold exactly like transfer() does:
        // OTP is not a terminal state, so it must stay PENDING, never PAID/failed.
        self::assertSame(PayoutStatusResult::PENDING, $result['status']);
        self::assertSame('action_required', $result['failure_code']);
    }

    public function testTransferStatusMapsQueuedSuccessWithNullTransferredAtToPending(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('get')->willReturn($this->responseOf(200, [
            'status' => true,
            'message' => 'Transfer has been queued',
            'data' => [
                'reference' => 'py_ref_1',
                'transfer_code' => 'TRF_1',
                'status' => 'success',
                'amount' => 5000,
                'transferred_at' => null,
            ],
        ]));

        $result = $this->gateway($http)->transferStatus('py_ref_1', 'TRF_1');

        // Reconcile path: a queued (not-yet-settled) success must stay PENDING --
        // a wrong PAID here would release the hold for money that hasn't moved.
        self::assertSame(PayoutStatusResult::PENDING, $result['status']);
    }

    public function testTransferStatusMapsFullReversal(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('get')->willReturn($this->responseOf(200, [
            'status' => true,
            'message' => 'ok',
            'data' => [
                'reference' => 'py_ref_1',
                'transfer_code' => 'TRF_1',
                'status' => 'reversed',
                'amount' => 5000,
            ],
        ]));

        $result = $this->gateway($http)->transferStatus('py_ref_1', 'TRF_1');

        self::assertSame(PayoutStatusResult::REVERSED, $result['status']);
        self::assertSame(5000, $result['reversed_amount']);
    }

    public function testTransferStatusUsesProviderSafeRefNotProviderRef(): void
    {
        $http = $this->createMock(Client::class);
        $http->expects(self::once())
            ->method('get')
            ->with(self::stringContains('/transfer/verify/py_ref_1'))
            ->willReturn($this->responseOf(200, [
                'status' => true,
                'message' => 'ok',
                'data' => ['reference' => 'py_ref_1', 'status' => 'success', 'transferred_at' => 'x'],
            ]));

        $this->gateway($http)->transferStatus('py_ref_1', 'TRF_should_be_unused');
    }

    public function testInspectAccountMapsReady(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('get')->willReturn($this->responseOf(200, [
            'status' => true,
            'message' => 'ok',
            'data' => ['recipient_code' => 'RCP_123', 'active' => true],
        ]));

        $result = $this->gateway($http)->inspectAccount('RCP_123');

        self::assertSame(DestinationStatus::READY, $result['state']);
    }

    public function testInspectAccountMapsRestrictedWhenInactive(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('get')->willReturn($this->responseOf(200, [
            'status' => true,
            'message' => 'ok',
            'data' => ['recipient_code' => 'RCP_123', 'active' => false],
        ]));

        $result = $this->gateway($http)->inspectAccount('RCP_123');

        self::assertSame(DestinationStatus::RESTRICTED, $result['state']);
    }

    public function testInspectAccountMapsPendingWhenNotFound(): void
    {
        $http = $this->createMock(Client::class);
        $http->method('get')->willReturn($this->responseOf(404, [
            'status' => false,
            'message' => 'Recipient not found',
        ]));

        $result = $this->gateway($http)->inspectAccount('RCP_missing');

        self::assertSame(DestinationStatus::RESTRICTED, $result['state']);
    }
}
