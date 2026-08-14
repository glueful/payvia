<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Services;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;

/**
 * Orphan-intent expiry (OUTSTANDING: orphan-intent expiry/sweeper).
 *
 * `payment_intents` had no expiry of any kind: an `initializing`/`open` row whose payable is
 * never resolved holds that payable's ACTIVE idempotency port forever (so the payable can never
 * claim a fresh attempt through any path that does not first prove the old session dead), and the
 * table grows without bound. This sweeper is the missing reaper.
 *
 * The semantics are deliberately blunt, and every clause of that bluntness matters:
 *
 *  - AGE IS THE ONLY CRITERION. Staleness is `COALESCE(updated_at, created_at)` older than
 *    `payvia.intents.stale_after_days` (default 30, clamped to 1..365). The sweeper never asks
 *    whether the payable "still legitimately holds" its intent, because nothing in this table can
 *    answer that honestly -- an `open` row looks identical whether the payer is mid-checkout or
 *    left a month ago. Any liveness probe, any re-check, any consultation of the payable is
 *    provider or host I/O this batch job has no business doing.
 *  - SWEEPING IS NOT DESTRUCTIVE, and that is what makes age-alone defensible. A swept row
 *    transitions to `failed` through the same re-keying CAS every terminal transition uses, which
 *    FREES the payable's active port. A swept payer who returns later therefore converges
 *    automatically via ensure-live's CREATE path in {@see PayviaPaymentCollector::initiate()}: no
 *    active row is found, a fresh attempt is claimed, a new provider session is created. Nothing
 *    is deleted, and the swept row keeps its own `reference`, so a late webhook for the abandoned
 *    session still resolves to that exact row and settles it.
 *  - BATCHED AND RESUMABLE. One call retires at most `$limit` rows, oldest `id` first. Because a
 *    retired row leaves the active statuses, the next call's own query excludes it -- repeated
 *    runs walk the backlog forward with no cursor to keep.
 *  - PER-ROW CAS. The batch is selected first and each row is retired individually, so an
 *    overlapping sweep (two cron hosts, an operator running the command by hand during the
 *    scheduled run) double-processes nothing: whoever gets there first wins the compare-and-swap
 *    and the loser's write is a no-op that is not counted.
 *
 * TENANT SCOPE: like every other Payvia repository operation, a sweep is scoped to the tenant the
 * bound {@see \Glueful\Extensions\Payvia\Tenancy\PayviaTenantResolver} resolves for `$context` --
 * the '' sentinel partition on a single-store install. A tenancy-enabled host runs the command
 * once per tenant, exactly as it does for every other tenant-scoped Payvia operation.
 */
final class StaleIntentSweeper
{
    public const DEFAULT_STALE_AFTER_DAYS = 30;
    public const MIN_STALE_AFTER_DAYS = 1;
    public const MAX_STALE_AFTER_DAYS = 365;

    /** One sweep's batch cap. Bounded so a first run against a long-neglected table is not a
     * single unbounded transaction-free scan-and-write; run the command repeatedly (or let cron
     * catch up over a few passes) to drain a large backlog. */
    public const DEFAULT_BATCH_LIMIT = 200;

    public function __construct(private PaymentIntentRepository $intents)
    {
    }

    /**
     * Retire up to `$limit` stale attempts and return how many actually transitioned.
     *
     * `$staleAfterDays` overrides `payvia.intents.stale_after_days` for this run (the console
     * command's `--stale-after-days`); it is clamped identically, so no caller can widen the
     * window past a year or narrow it to "everything since a moment ago".
     */
    public function sweep(
        ApplicationContext $context,
        ?int $staleAfterDays = null,
        int $limit = self::DEFAULT_BATCH_LIMIT,
    ): int {
        $days = $this->staleAfterDays($context, $staleAfterDays);
        $cutoff = (new \DateTimeImmutable())->modify('-' . $days . ' days');

        $swept = 0;
        foreach ($this->intents->findStale($context, $cutoff, $limit) as $row) {
            $uuid = (string) ($row['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }

            // Per-row CAS: `false` means someone else retired this row between the batch read and
            // now (an overlapping sweep, a settlement, a renewal). Not an error, and not counted.
            if ($this->intents->expireStale($context, $uuid)) {
                $swept++;
            }
        }

        return $swept;
    }

    /**
     * The effective window in days: `$override` when given, else `payvia.intents.stale_after_days`,
     * else {@see DEFAULT_STALE_AFTER_DAYS} -- always clamped to
     * {@see MIN_STALE_AFTER_DAYS}..{@see MAX_STALE_AFTER_DAYS}.
     *
     * The clamp is a real guard, not cosmetics. A 0 (or negative) value read from a typo'd env var
     * would otherwise mean "sweep everything, including the attempt claimed one second ago",
     * cancelling live checkouts wholesale; a huge value would mean the table never drains at all.
     */
    public function staleAfterDays(ApplicationContext $context, ?int $override = null): int
    {
        $configured = $override ?? config($context, 'payvia.intents.stale_after_days', self::DEFAULT_STALE_AFTER_DAYS);
        $days = is_numeric($configured) ? (int) $configured : self::DEFAULT_STALE_AFTER_DAYS;

        return max(self::MIN_STALE_AFTER_DAYS, min(self::MAX_STALE_AFTER_DAYS, $days));
    }
}
