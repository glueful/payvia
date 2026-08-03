<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Extensions\Payvia\Database\Migrations\AddProviderEventDispatchClaimToken;
use Glueful\Extensions\Payvia\Database\Migrations\CreateProviderEventsTable;
use Glueful\Extensions\Payvia\Repositories\ProviderEventRepository;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

final class ProviderEventRepositoryLeaseTest extends PayviaTestCase
{
    private ProviderEventRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigration(new CreateProviderEventsTable());
        $this->runMigration(new AddProviderEventDispatchClaimToken());
        $this->repo = new ProviderEventRepository($this->connection);
    }

    /** @return array<string,mixed> */
    private function row(string $deliveryKey, string $logicalKey, string $type = 'payment.succeeded'): array
    {
        return [
            'gateway' => 'paystack',
            'source' => 'webhook',
            'provider_event_id' => null,
            'delivery_key' => $deliveryKey,
            'logical_event_key' => $logicalKey,
            'type' => $type,
            'signature_valid' => true,
            'normalized_payload' => ['reference' => 'R'],
            'raw_payload' => null,
        ];
    }

    private function makePendingRow(string $deliveryKey, string $logicalKey): string
    {
        $uuid = $this->repo->insertReceived($this->row($deliveryKey, $logicalKey));
        self::assertNotNull($uuid);
        $this->repo->markProcessed($uuid);

        return $uuid;
    }

    private function forceStaleClaim(string $uuid): void
    {
        $this->connection->table('provider_events')
            ->where(['uuid' => $uuid])
            ->update([
                'dispatch_claimed_at' => $this->connection->getDriver()
                    ->formatDateTime((new \DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s')),
            ]);
    }

    public function testAcquireOnPendingRowReturnsTokenAndMarksDispatching(): void
    {
        $this->makePendingRow('d1', 'payment.succeeded:L1');

        $token = $this->repo->acquireLogicalDispatchLease('paystack', 'payment.succeeded:L1');

        self::assertNotNull($token);
        self::assertNotSame('', $token);

        $row = $this->connection->table('provider_events')
            ->where(['gateway' => 'paystack', 'logical_event_key' => 'payment.succeeded:L1'])
            ->first();
        self::assertSame('dispatching', $row['dispatch_status']);
        self::assertSame($token, $row['dispatch_claim_token']);
    }

    public function testWrongGatewayKeyOrTokenCannotReleaseOrComplete(): void
    {
        $this->makePendingRow('d2', 'payment.succeeded:L2');
        $token = $this->repo->acquireLogicalDispatchLease('paystack', 'payment.succeeded:L2');
        self::assertNotNull($token);

        self::assertFalse($this->repo->completeLogicalDispatch('wrong-gateway', 'payment.succeeded:L2', $token));
        self::assertFalse($this->repo->completeLogicalDispatch('paystack', 'wrong-key', $token));
        self::assertFalse($this->repo->completeLogicalDispatch('paystack', 'payment.succeeded:L2', 'wrong-token'));

        self::assertFalse($this->repo->releaseLogicalDispatch('wrong-gateway', 'payment.succeeded:L2', $token));
        self::assertFalse($this->repo->releaseLogicalDispatch('paystack', 'wrong-key', $token));
        self::assertFalse($this->repo->releaseLogicalDispatch('paystack', 'payment.succeeded:L2', 'wrong-token'));

        $row = $this->connection->table('provider_events')
            ->where(['gateway' => 'paystack', 'logical_event_key' => 'payment.succeeded:L2'])
            ->first();
        self::assertSame('dispatching', $row['dispatch_status']);
        self::assertSame($token, $row['dispatch_claim_token']);
    }

    public function testReleaseClearsOnlyTheMatchingLease(): void
    {
        $this->makePendingRow('d3', 'payment.succeeded:L3');
        $token = $this->repo->acquireLogicalDispatchLease('paystack', 'payment.succeeded:L3');
        self::assertNotNull($token);

        self::assertTrue($this->repo->releaseLogicalDispatch('paystack', 'payment.succeeded:L3', $token));

        $row = $this->connection->table('provider_events')
            ->where(['gateway' => 'paystack', 'logical_event_key' => 'payment.succeeded:L3'])
            ->first();
        self::assertSame('pending', $row['dispatch_status']);
        self::assertNull($row['dispatch_claim_token']);
        self::assertNull($row['dispatch_claimed_at']);

        // Released lease token cannot be used a second time.
        self::assertFalse($this->repo->releaseLogicalDispatch('paystack', 'payment.succeeded:L3', $token));
    }

    public function testCompletionFinalizesOnlyTheMatchingLeaseAndCannotReopenDispatchedRow(): void
    {
        $this->makePendingRow('d4', 'payment.succeeded:L4');
        $token = $this->repo->acquireLogicalDispatchLease('paystack', 'payment.succeeded:L4');
        self::assertNotNull($token);

        self::assertTrue($this->repo->completeLogicalDispatch('paystack', 'payment.succeeded:L4', $token));

        $row = $this->connection->table('provider_events')
            ->where(['gateway' => 'paystack', 'logical_event_key' => 'payment.succeeded:L4'])
            ->first();
        self::assertSame('dispatched', $row['dispatch_status']);
        self::assertNotNull($row['dispatched_at']);
        self::assertNull($row['dispatch_claim_token']);

        // A dispatched row can never be reopened by replaying the same (now-cleared) token.
        self::assertFalse($this->repo->completeLogicalDispatch('paystack', 'payment.succeeded:L4', $token));
        self::assertFalse($this->repo->releaseLogicalDispatch('paystack', 'payment.succeeded:L4', $token));

        $row = $this->connection->table('provider_events')
            ->where(['gateway' => 'paystack', 'logical_event_key' => 'payment.succeeded:L4'])
            ->first();
        self::assertSame('dispatched', $row['dispatch_status']);
    }

    /**
     * This test is the authority for claim ownership: key/status scoping alone is
     * insufficient. A acquires the lease; its claim is then forced stale (a direct
     * UPDATE of dispatch_claimed_at simulating time passing, exactly like
     * RelayEventsTest::testRelayRecoversStaleDispatchClaimExactlyOnce). B then acquires
     * and MUST receive a token different from A's. Once B holds the lease, A's stale
     * token must be rejected by both release() and completeLogicalDispatch() -- the row
     * must remain dispatching under B's token throughout -- and B must still be able to
     * complete using its own token.
     */
    public function testStaleOwnerCannotReleaseOrCompleteAfterAnotherAcquiresTheLease(): void
    {
        $uuid = $this->makePendingRow('d5', 'payment.succeeded:L5');

        $tokenA = $this->repo->acquireLogicalDispatchLease('paystack', 'payment.succeeded:L5', staleSeconds: 300);
        self::assertNotNull($tokenA);

        $this->forceStaleClaim($uuid);

        $tokenB = $this->repo->acquireLogicalDispatchLease('paystack', 'payment.succeeded:L5', staleSeconds: 300);
        self::assertNotNull($tokenB);
        self::assertNotSame($tokenA, $tokenB);

        self::assertFalse($this->repo->releaseLogicalDispatch('paystack', 'payment.succeeded:L5', $tokenA));
        self::assertFalse($this->repo->completeLogicalDispatch('paystack', 'payment.succeeded:L5', $tokenA));

        $row = $this->connection->table('provider_events')
            ->where(['gateway' => 'paystack', 'logical_event_key' => 'payment.succeeded:L5'])
            ->first();
        self::assertSame('dispatching', $row['dispatch_status']);
        self::assertSame($tokenB, $row['dispatch_claim_token']);

        self::assertTrue($this->repo->completeLogicalDispatch('paystack', 'payment.succeeded:L5', $tokenB));

        $row = $this->connection->table('provider_events')
            ->where(['gateway' => 'paystack', 'logical_event_key' => 'payment.succeeded:L5'])
            ->first();
        self::assertSame('dispatched', $row['dispatch_status']);
        self::assertNull($row['dispatch_claim_token']);
    }
}
