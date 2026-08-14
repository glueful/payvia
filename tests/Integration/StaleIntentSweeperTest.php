<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Extensions\Payvia\Database\Migrations\AddPaymentIntentAttemptLifecycle;
use Glueful\Extensions\Payvia\Database\Migrations\CreatePaymentIntentsTable;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;
use Glueful\Extensions\Payvia\Services\StaleIntentSweeper;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

/**
 * Orphan-intent expiry (OUTSTANDING: orphan-intent expiry/sweeper).
 *
 * An `initializing`/`open` `payment_intents` row whose payable is never resolved holds that
 * payable's active idempotency port forever, and the table grows without bound. The sweeper
 * retires such rows to `failed` through the SAME re-keying CAS every other terminal transition
 * uses, which frees the port -- so a payer who comes back after their intent was swept simply
 * gets a fresh attempt from ensure-live's create path.
 *
 * AGE IS THE ONLY CRITERION. The sweeper never asks whether the payable "still wants" its
 * intent, because nothing in this table can answer that question honestly.
 */
final class StaleIntentSweeperTest extends PayviaTestCase
{
    private PaymentIntentRepository $intents;
    private StaleIntentSweeper $sweeper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runMigration(new CreatePaymentIntentsTable());
        $this->runMigration(new AddPaymentIntentAttemptLifecycle());
        $this->context->mergeConfigDefaults('payvia', require __DIR__ . '/../../config/payvia.php');

        $this->intents = new PaymentIntentRepository($this->connection);
        $this->sweeper = new StaleIntentSweeper($this->intents);
    }

    // ==================================================================
    // What gets swept
    // ==================================================================

    public function testAnInitializingRowOlderThanTheWindowIsFailedAndItsPortFreed(): void
    {
        $uuid = $this->seed('ord-init-stale', PaymentIntentRepository::STATUS_INITIALIZING);
        $this->backdate($uuid, 40, null);

        self::assertSame(1, $this->sweeper->sweep($this->context));

        $row = $this->row($uuid);
        self::assertSame('failed', $row['status']);
        self::assertNotSame(
            'commerce_order:ord-init-stale',
            (string) $row['idempotency_key'],
            'the active port must be freed by the same re-keying CAS every other retirement uses'
        );
    }

    public function testAnOpenRowOlderThanTheWindowIsFailedToo(): void
    {
        $uuid = $this->seed('ord-open-stale', PaymentIntentRepository::STATUS_OPEN);
        $this->backdate($uuid, 40, 40);

        self::assertSame(1, $this->sweeper->sweep($this->context));

        $row = $this->row($uuid);
        self::assertSame('failed', $row['status']);
        self::assertSame(
            'sess_' . $uuid,
            (string) $row['reference'],
            'the provider reference stays webhook-addressable on the swept row'
        );
    }

    public function testRowsInsideTheWindowAreNeverSwept(): void
    {
        $fresh = $this->seed('ord-fresh', PaymentIntentRepository::STATUS_OPEN);
        $this->backdate($fresh, 29, 29);

        self::assertSame(0, $this->sweeper->sweep($this->context));
        self::assertSame('open', $this->row($fresh)['status']);
    }

    public function testAlreadyRetiredRowsAreNeverSweptAgain(): void
    {
        $superseded = $this->seed('ord-superseded', PaymentIntentRepository::STATUS_SUPERSEDED);
        $closed = $this->seed('ord-closed', PaymentIntentRepository::STATUS_CLOSED);
        $failed = $this->seed('ord-failed', PaymentIntentRepository::STATUS_FAILED);
        foreach ([$superseded, $closed, $failed] as $uuid) {
            $this->backdate($uuid, 400, 400);
        }

        self::assertSame(0, $this->sweeper->sweep($this->context));
        self::assertSame('superseded', $this->row($superseded)['status']);
        self::assertSame('closed', $this->row($closed)['status']);
        self::assertSame('failed', $this->row($failed)['status']);
    }

    public function testARecentUpdateKeepsAnOldRowAlive(): void
    {
        // Staleness is measured from the LAST touch, not from creation: a long-lived attempt that
        // was re-probed yesterday is not an orphan.
        $uuid = $this->seed('ord-touched', PaymentIntentRepository::STATUS_OPEN);
        $this->backdate($uuid, 400, 1);

        self::assertSame(0, $this->sweeper->sweep($this->context));
        self::assertSame('open', $this->row($uuid)['status']);
    }

    public function testANullUpdatedAtFallsBackToCreatedAt(): void
    {
        // A claimed attempt that never reached markOpen() has no `updated_at` at all.
        $uuid = $this->seed('ord-never-updated', PaymentIntentRepository::STATUS_INITIALIZING);
        $this->backdate($uuid, 90, null);
        self::assertNull($this->row($uuid)['updated_at']);

        self::assertSame(1, $this->sweeper->sweep($this->context));
        self::assertSame('failed', $this->row($uuid)['status']);
    }

    // ==================================================================
    // Batching + per-row CAS
    // ==================================================================

    public function testTheBatchLimitCapsOneSweepAndTheNextCallContinuesInIdOrder(): void
    {
        $uuids = [];
        foreach (range(1, 5) as $n) {
            $uuid = $this->seed('ord-batch-' . $n, PaymentIntentRepository::STATUS_OPEN);
            $this->backdate($uuid, 40, 40);
            $uuids[] = $uuid;
        }

        self::assertSame(2, $this->sweeper->sweep($this->context, null, 2));
        self::assertSame(['failed', 'failed', 'open', 'open', 'open'], $this->statuses($uuids));

        self::assertSame(2, $this->sweeper->sweep($this->context, null, 2));
        self::assertSame(['failed', 'failed', 'failed', 'failed', 'open'], $this->statuses($uuids));

        self::assertSame(1, $this->sweeper->sweep($this->context, null, 2));
        self::assertSame(0, $this->sweeper->sweep($this->context, null, 2));
    }

    public function testAnOverlappingSweepDoubleProcessesNothing(): void
    {
        // Per-row CAS: whoever gets there first wins, and the loser's write is a no-op rather
        // than a second retirement (which would re-key the row a second time and mint a
        // misleading `updated_at`).
        $uuid = $this->seed('ord-race', PaymentIntentRepository::STATUS_OPEN);
        $this->backdate($uuid, 40, 40);

        self::assertTrue($this->intents->expireStale($this->context, $uuid));
        $afterFirst = $this->row($uuid);

        self::assertFalse($this->intents->expireStale($this->context, $uuid));
        self::assertSame($afterFirst, $this->row($uuid));
    }

    public function testASweptPayableCanImmediatelyClaimAFreshAttempt(): void
    {
        // The documented convergence: a swept payer who returns is not wedged -- the port is
        // free, so ensure-live's create path claims a brand-new attempt.
        $uuid = $this->seed('ord-returning', PaymentIntentRepository::STATUS_OPEN);
        $this->backdate($uuid, 40, 40);
        self::assertSame(1, $this->sweeper->sweep($this->context));

        self::assertNull($this->intents->findActive($this->context, 'commerce_order', 'ord-returning'));

        $fresh = $this->intents->claimAttempt($this->context, [
            'payable_type' => 'commerce_order',
            'payable_id' => 'ord-returning',
            'gateway' => 'fake',
            'amount' => 4999,
            'currency' => 'GHS',
        ]);

        self::assertNotSame($uuid, (string) $fresh['uuid']);
        self::assertSame('initializing', (string) $fresh['status']);
        self::assertSame('commerce_order:ord-returning', (string) $fresh['idempotency_key']);
    }

    // ==================================================================
    // The configured window
    // ==================================================================

    public function testTheConfiguredWindowDefaultsToThirtyDays(): void
    {
        self::assertSame(30, $this->sweeper->staleAfterDays($this->context));
    }

    public function testTheConfiguredWindowIsClampedToOneDayAtTheBottom(): void
    {
        $this->context->mergeConfigDefaults('payvia', ['intents' => ['stale_after_days' => 0]]);
        self::assertSame(1, $this->sweeper->staleAfterDays($this->context));

        $this->context->mergeConfigDefaults('payvia', ['intents' => ['stale_after_days' => -50]]);
        self::assertSame(1, $this->sweeper->staleAfterDays($this->context));
    }

    public function testTheConfiguredWindowIsClampedToAYearAtTheTop(): void
    {
        $this->context->mergeConfigDefaults('payvia', ['intents' => ['stale_after_days' => 5000]]);
        self::assertSame(365, $this->sweeper->staleAfterDays($this->context));
    }

    public function testAnExplicitOverrideIsClampedTheSameWay(): void
    {
        self::assertSame(1, $this->sweeper->staleAfterDays($this->context, 0));
        self::assertSame(365, $this->sweeper->staleAfterDays($this->context, 9999));
        self::assertSame(7, $this->sweeper->staleAfterDays($this->context, 7));
    }

    public function testAClampedWindowIsWhatTheSweepActuallyUses(): void
    {
        $this->context->mergeConfigDefaults('payvia', ['intents' => ['stale_after_days' => 0]]);

        $twoDays = $this->seed('ord-two-days', PaymentIntentRepository::STATUS_OPEN);
        $this->backdate($twoDays, 2, 2);
        $sameDay = $this->seed('ord-same-day', PaymentIntentRepository::STATUS_OPEN);

        self::assertSame(1, $this->sweeper->sweep($this->context));
        self::assertSame('failed', $this->row($twoDays)['status']);
        self::assertSame('open', $this->row($sameDay)['status'], '0 clamps to 1 day, never to "now"');
    }

    // ==================================================================
    // helpers
    // ==================================================================

    private function seed(string $payableId, string $status): string
    {
        $claim = $this->intents->claimAttempt($this->context, [
            'payable_type' => 'commerce_order',
            'payable_id' => $payableId,
            'gateway' => 'fake',
            'amount' => 4999,
            'currency' => 'GHS',
        ]);
        $uuid = (string) $claim['uuid'];

        if ($status === PaymentIntentRepository::STATUS_INITIALIZING) {
            return $uuid;
        }

        if ($status === PaymentIntentRepository::STATUS_FAILED) {
            self::assertTrue($this->intents->fail($this->context, $uuid));

            return $uuid;
        }

        self::assertTrue($this->intents->markOpen(
            $this->context,
            $uuid,
            'sess_' . $uuid,
            ['checkout_url' => 'https://checkout.test/' . $uuid],
        ));

        if ($status === PaymentIntentRepository::STATUS_SUPERSEDED) {
            self::assertTrue($this->intents->supersede($this->context, $uuid));
        }

        if ($status === PaymentIntentRepository::STATUS_CLOSED) {
            $this->intents->close($this->context, $uuid);
        }

        return $uuid;
    }

    private function backdate(string $uuid, int $createdDaysAgo, ?int $updatedDaysAgo): void
    {
        $this->connection->table('payment_intents')->where(['uuid' => $uuid])->update([
            'created_at' => $this->daysAgo($createdDaysAgo),
            'updated_at' => $updatedDaysAgo === null ? null : $this->daysAgo($updatedDaysAgo),
        ]);
    }

    private function daysAgo(int $days): string
    {
        return (new \DateTimeImmutable('-' . $days . ' days'))->format('Y-m-d H:i:s');
    }

    /** @return array<string,mixed> */
    private function row(string $uuid): array
    {
        $row = $this->connection->table('payment_intents')
            ->select(['*'])
            ->where(['uuid' => $uuid])
            ->first();
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @param list<string> $uuids
     * @return list<string>
     */
    private function statuses(array $uuids): array
    {
        return array_map(fn(string $uuid): string => (string) $this->row($uuid)['status'], $uuids);
    }
}
