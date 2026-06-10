<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Extensions\Payvia\Database\Migrations\CreateProviderEventsTable;
use Glueful\Extensions\Payvia\Repositories\ProviderEventRepository;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

final class ProviderEventRepositoryTest extends PayviaTestCase
{
    private ProviderEventRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigration(new CreateProviderEventsTable());
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

    public function testInsertAndStatusTransitions(): void
    {
        $uuid = $this->repo->insertReceived($this->row('d1', 'payment.succeeded:R1'));
        self::assertNotNull($uuid);

        $this->repo->markProcessing($uuid);
        $this->repo->markProcessed($uuid);
        $stored = $this->repo->findByUuid($uuid);

        self::assertSame('processed', $stored['status']);
        self::assertSame('pending', $stored['dispatch_status']);
    }

    public function testDuplicateDeliveryKeyInsertReturnsNull(): void
    {
        self::assertNotNull($this->repo->insertReceived($this->row('dup', 'payment.succeeded:R2')));
        self::assertNull($this->repo->insertReceived($this->row('dup', 'payment.succeeded:R2')));
    }

    public function testAtomicClaimWinsOnceThenZero(): void
    {
        self::assertNotNull($this->repo->insertReceived($this->row('d-verify', 'payment.succeeded:R3')));
        self::assertNotNull($this->repo->insertReceived($this->row('d-webhook', 'payment.succeeded:R3')));

        self::assertFalse($this->repo->isLogicalDispatched('paystack', 'payment.succeeded:R3'));
        self::assertGreaterThanOrEqual(1, $this->repo->claimLogicalForDispatch('paystack', 'payment.succeeded:R3'));
        self::assertSame(0, $this->repo->claimLogicalForDispatch('paystack', 'payment.succeeded:R3'));

        $this->repo->markLogicalDispatched('paystack', 'payment.succeeded:R3');
        self::assertTrue($this->repo->isLogicalDispatched('paystack', 'payment.succeeded:R3'));
    }

    public function testFindDispatchableIncludesStaleDispatchingRows(): void
    {
        $uuid = $this->repo->insertReceived($this->row('stale', 'payment.succeeded:R4'));
        self::assertNotNull($uuid);
        $this->repo->markProcessed($uuid);
        $this->repo->claimLogicalForDispatch('paystack', 'payment.succeeded:R4');

        $this->connection->table('provider_events')
            ->where(['uuid' => $uuid])
            ->update([
                'dispatch_claimed_at' => (new \DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s'),
            ]);

        $rows = $this->repo->findDispatchable(staleSeconds: 300);
        self::assertCount(1, $rows);

        self::assertSame(1, $this->repo->reclaimStaleDispatching('paystack', 'payment.succeeded:R4', 300));
        self::assertSame(0, $this->repo->reclaimStaleDispatching('paystack', 'payment.succeeded:R4', 300));
    }
}
