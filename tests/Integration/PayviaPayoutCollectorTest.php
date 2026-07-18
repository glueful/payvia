<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Extensions\Contracts\Payments\DestinationStatus;
use Glueful\Extensions\Contracts\Payments\PayoutCollector;
use Glueful\Extensions\Contracts\Payments\PayoutDestination;
use Glueful\Extensions\Contracts\Payments\PayoutRequest;
use Glueful\Extensions\Contracts\Payments\PayoutResult;
use Glueful\Extensions\Contracts\Payments\PayoutStatusResult;
use Glueful\Extensions\Payvia\Database\Migrations\CreatePayviaTransfersTable;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\PayoutTransferRepository;
use Glueful\Extensions\Payvia\PayviaServiceProvider;
use Glueful\Extensions\Payvia\Services\PayviaPayoutCollector;
use Glueful\Extensions\Payvia\Support\ProviderSafeReference;
use Glueful\Extensions\Payvia\Tests\Support\FakeTransferGateway;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

final class PayviaPayoutCollectorTest extends PayviaTestCase
{
    private FakeTransferGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runMigration(new CreatePayviaTransfersTable());

        $this->gateway = new FakeTransferGateway();
    }

    public function testTransferPersistsTheAttemptRowBeforeTheGatewayCallEvenWhenTheGatewayFails(): void
    {
        $this->gateway->transferException = new \RuntimeException('network timeout');
        $collector = $this->collector();
        $destination = new PayoutDestination('paystack', 'RCP_123');
        $request = new PayoutRequest(5000, 'GHS', 'payoutAAA01:attempt:1');

        try {
            $collector->transfer($this->context, $destination, $request);
            self::fail('Expected the gateway exception to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('network timeout', $e->getMessage());
        }

        $row = $this->findRow('paystack', 'payoutAAA01:attempt:1');
        self::assertNotNull($row);
        self::assertSame('pending', $row['status']);
        self::assertNull($row['provider_ref']);
        self::assertSame(ProviderSafeReference::forPaystack('payoutAAA01:attempt:1'), $row['provider_reference']);
    }

    public function testReplayAfterAKnownResultReturnsTheSameResultWithoutASecondTransferCall(): void
    {
        $this->gateway->transferResult = [
            'status' => PayoutResult::PAID,
            'provider_ref' => 'TRF_1',
            'failure_code' => null,
            'failure_reason' => null,
            'raw' => ['id' => 'TRF_1'],
        ];
        $collector = $this->collector();
        $destination = new PayoutDestination('paystack', 'RCP_123');
        $request = new PayoutRequest(5000, 'GHS', 'payoutAAA02:attempt:1');

        $first = $collector->transfer($this->context, $destination, $request);
        $second = $collector->transfer($this->context, $destination, $request);

        self::assertSame(1, $this->gateway->transferCalls);
        self::assertSame(PayoutResult::PAID, $first->status);
        self::assertSame('TRF_1', $first->providerRef);
        self::assertSame($first->status, $second->status);
        self::assertSame($first->providerRef, $second->providerRef);
    }

    public function testLostResponseRecoveryReconcilesViaStatusWithoutASecondTransferCall(): void
    {
        $this->gateway->transferException = new \RuntimeException('connection reset');
        $collector = $this->collector();
        $destination = new PayoutDestination('paystack', 'RCP_123');
        $request = new PayoutRequest(5000, 'GHS', 'payoutAAA03:attempt:1');

        try {
            $collector->transfer($this->context, $destination, $request);
            self::fail('Expected the gateway exception to propagate.');
        } catch (\RuntimeException) {
            // Expected -- the row now sits pending with no provider_ref:
            // the exact lost-response shape.
        }

        self::assertSame(1, $this->gateway->transferCalls);

        // The provider actually processed the transfer -- a replay must
        // recover it via transferStatus() (verify-by-reference), never a
        // second transfer() call -- Paystack rejects a duplicate reference.
        $this->gateway->transferException = null;
        $this->gateway->transferStatusResult = [
            'status' => PayoutStatusResult::PAID,
            'reversed_amount' => 0,
            'provider_ref' => 'TRF_RECOVERED',
            'failure_code' => null,
            'failure_reason' => null,
            'raw' => ['id' => 'TRF_RECOVERED'],
        ];

        $result = $collector->transfer($this->context, $destination, $request);

        self::assertSame(1, $this->gateway->recoverTransferCalls);
        self::assertSame(1, $this->gateway->transferCalls, 'transfer() must not be called again');
        self::assertSame(1, $this->gateway->transferStatusCalls);
        self::assertSame(PayoutResult::PAID, $result->status);
        self::assertSame('TRF_RECOVERED', $result->providerRef);

        $row = $this->findRow('paystack', 'payoutAAA03:attempt:1');
        self::assertNotNull($row);
        self::assertSame('TRF_RECOVERED', $row['provider_ref']);
    }

    public function testStripeLostResponseRecoversViaIdempotentReplayWithoutTransferStatus(): void
    {
        // Before the fix, this exact shape (Stripe pre-I/O row, no
        // provider_ref) reconciled via transferStatus(), which THROWS for
        // Stripe without a known provider_ref -- the payout stayed UNKNOWN
        // forever. The fix recovers via the gateway's own recoverTransfer(),
        // which for Stripe replays transfer() under the same idempotency
        // key -- a safe replay, never a second money-moving create.
        $this->gateway->recoverTransferMode = 'replay';
        $this->gateway->transferException = new \RuntimeException('connection reset');
        $collector = $this->collector('stripe');
        $destination = new PayoutDestination('stripe', 'acct_123');
        $request = new PayoutRequest(5000, 'USD', 'payoutSTRIPE01:attempt:1');

        try {
            $collector->transfer($this->context, $destination, $request);
            self::fail('Expected the gateway exception to propagate.');
        } catch (\RuntimeException) {
            // Expected -- the row now sits pending with no provider_ref:
            // the exact lost-response shape that stranded a Stripe payout
            // permanently as UNKNOWN before this fix.
        }

        self::assertSame(1, $this->gateway->transferCalls);

        // Stripe actually processed the CREATE, but the response was lost.
        // A replay under the identical idempotency key must recover the
        // ORIGINAL transfer.
        $this->gateway->transferException = null;
        $this->gateway->transferResult = [
            'status' => PayoutResult::PAID,
            'provider_ref' => 'tr_recovered_stripe',
            'failure_code' => null,
            'failure_reason' => null,
            'raw' => ['id' => 'tr_recovered_stripe'],
        ];

        $result = $collector->transfer($this->context, $destination, $request);

        self::assertSame(1, $this->gateway->recoverTransferCalls);
        self::assertSame(
            0,
            $this->gateway->transferStatusCalls,
            'Stripe recovery must never call transferStatus() -- it throws without a provider_ref'
        );
        self::assertSame(
            2,
            $this->gateway->transferCalls,
            'the replay reuses transfer() under the same idempotency key, not a distinct gateway call'
        );
        self::assertCount(2, $this->gateway->transferProviderSafeRefs);
        self::assertSame(
            $this->gateway->transferProviderSafeRefs[0],
            $this->gateway->transferProviderSafeRefs[1],
            'the replay must reuse the identical Idempotency-Key as the original lost attempt'
        );
        self::assertSame(PayoutResult::PAID, $result->status);
        self::assertSame('tr_recovered_stripe', $result->providerRef);

        $row = $this->findRow('stripe', 'payoutSTRIPE01:attempt:1');
        self::assertNotNull($row);
        self::assertSame('tr_recovered_stripe', $row['provider_ref']);
    }

    public function testStatusWithNoRowReturnsAttemptNotStarted(): void
    {
        $collector = $this->collector();
        $destination = new PayoutDestination('paystack', 'RCP_123');

        $result = $collector->status($this->context, $destination, 'payoutAAA04:attempt:1');

        self::assertSame(PayoutStatusResult::RETRYABLE_FAILURE, $result->status);
        self::assertSame('attempt_not_started', $result->failureCode);
        self::assertSame(0, $result->reversedAmount);
    }

    public function testStatusMapsFullReversal(): void
    {
        $collector = $this->collector();
        $destination = new PayoutDestination('paystack', 'RCP_123');
        $request = new PayoutRequest(5000, 'GHS', 'payoutAAA05:attempt:1');

        $collector->transfer($this->context, $destination, $request);

        $this->gateway->transferStatusResult = [
            'status' => PayoutStatusResult::REVERSED,
            'reversed_amount' => 5000,
            'provider_ref' => 'fake_ref_1',
            'failure_code' => null,
            'failure_reason' => null,
            'raw' => [],
        ];

        $result = $collector->status($this->context, $destination, 'payoutAAA05:attempt:1');

        self::assertSame(PayoutStatusResult::REVERSED, $result->status);
        self::assertSame(5000, $result->reversedAmount);
    }

    public function testStatusMapsPartialReversal(): void
    {
        $collector = $this->collector();
        $destination = new PayoutDestination('paystack', 'RCP_123');
        $request = new PayoutRequest(5000, 'GHS', 'payoutAAA06:attempt:1');

        $collector->transfer($this->context, $destination, $request);

        $this->gateway->transferStatusResult = [
            'status' => PayoutStatusResult::PAID,
            'reversed_amount' => 2000,
            'provider_ref' => 'fake_ref_1',
            'failure_code' => null,
            'failure_reason' => null,
            'raw' => [],
        ];

        $result = $collector->status($this->context, $destination, 'payoutAAA06:attempt:1');

        self::assertSame(PayoutStatusResult::PAID, $result->status);
        self::assertSame(2000, $result->reversedAmount);
    }

    public function testPaystackActionRequiredRemainsPendingThroughTheCollector(): void
    {
        $this->gateway->transferResult = [
            'status' => PayoutResult::PENDING,
            'provider_ref' => 'TRF_OTP',
            'failure_code' => 'action_required',
            'failure_reason' => 'Transfer requires OTP confirmation.',
            'raw' => [],
        ];
        $collector = $this->collector();
        $destination = new PayoutDestination('paystack', 'RCP_123');
        $request = new PayoutRequest(5000, 'GHS', 'payoutAAA07:attempt:1');

        $result = $collector->transfer($this->context, $destination, $request);

        self::assertSame(PayoutResult::PENDING, $result->status);
        self::assertSame('action_required', $result->failureCode);
        self::assertSame('TRF_OTP', $result->providerRef);
    }

    public function testInspectDestinationMapsReadiness(): void
    {
        $collector = $this->collector();
        $destination = new PayoutDestination('paystack', 'RCP_123');

        $this->gateway->inspectAccountResult = ['state' => DestinationStatus::READY, 'failure_code' => null];
        self::assertSame(
            DestinationStatus::READY,
            $collector->inspectDestination($this->context, $destination)->state
        );

        $this->gateway->inspectAccountResult = ['state' => DestinationStatus::PENDING, 'failure_code' => null];
        self::assertSame(
            DestinationStatus::PENDING,
            $collector->inspectDestination($this->context, $destination)->state
        );

        $this->gateway->inspectAccountResult = [
            'state' => DestinationStatus::RESTRICTED,
            'failure_code' => 'recipient_inactive',
        ];
        $restricted = $collector->inspectDestination($this->context, $destination);
        self::assertSame(DestinationStatus::RESTRICTED, $restricted->state);
        self::assertSame('recipient_inactive', $restricted->failureCode);
    }

    public function testProviderBindsSharedPayoutCollectorContract(): void
    {
        $services = PayviaServiceProvider::services();

        self::assertSame(PayviaPayoutCollector::class, $services[PayoutCollector::class]['class'] ?? null);
    }

    private function collector(string $gatewayKey = 'paystack'): PayviaPayoutCollector
    {
        $this->bind(FakeTransferGateway::class, $this->gateway);
        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver($gatewayKey, FakeTransferGateway::class);

        return new PayviaPayoutCollector($manager, new PayoutTransferRepository($this->connection));
    }

    /** @return array<string,mixed>|null */
    private function findRow(string $gateway, string $idempotencyKey): ?array
    {
        $repo = new PayoutTransferRepository($this->connection);

        return $repo->findByIdempotencyKey($this->context, $gateway, $idempotencyKey);
    }
}
