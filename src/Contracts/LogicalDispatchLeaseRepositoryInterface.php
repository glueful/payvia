<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Contracts;

/**
 * Owner-fenced logical-dispatch leases.
 *
 * Deliberately NOT folded into {@see ProviderEventRepositoryInterface}: that interface is a
 * public substitution seam, and widening it with a new abstract method would break existing
 * implementations in a 2.4 minor release. This is an additive capability a repository MAY also
 * implement.
 *
 * `claimLogicalForDispatch()`/`reclaimStaleDispatching()`/`markLogicalDispatched()` on
 * {@see ProviderEventRepositoryInterface} scope a claim by (gateway, logical_event_key,
 * dispatch_status) alone: any caller who observes a row `dispatching` can reclaim or finalize it,
 * including a second caller racing a stale reclaim. These lease methods add a per-acquisition
 * opaque token so only the caller holding the token that WON the acquiring update can complete or
 * release it -- a stale former owner's completion/release is fenced out even though the row is
 * still (correctly) `dispatching`.
 */
interface LogicalDispatchLeaseRepositoryInterface
{
    /**
     * Atomically claim the logical dispatch lease for (gateway, logicalEventKey).
     *
     * Matches rows that are either `pending`, or `dispatching` with a `dispatch_claimed_at`
     * older than $staleSeconds, and in one update stamps them `dispatching` with a freshly
     * generated opaque token and the current claim time. Returns that token only when this
     * call's own update matched at least one row (i.e. it won the race); otherwise null.
     */
    public function acquireLogicalDispatchLease(
        string $gateway,
        string $logicalEventKey,
        int $staleSeconds = 300,
    ): ?string;

    /**
     * Finalize a held lease: matches only rows that are `dispatching` for
     * (gateway, logicalEventKey) AND carry exactly $leaseToken. On a match, writes
     * `dispatched`/`dispatched_at` and clears the token. Returns whether this fenced update
     * matched (won) any row -- a stale or wrong token returns false without changing anything,
     * and a row already `dispatched` can never be reopened this way.
     */
    public function completeLogicalDispatch(
        string $gateway,
        string $logicalEventKey,
        string $leaseToken,
    ): bool;

    /**
     * Release a held lease back to pending: matches only rows that are `dispatching` for
     * (gateway, logicalEventKey) AND carry exactly $leaseToken. On a match, writes `pending` and
     * clears both the token and the claim timestamp. Returns whether this fenced update matched
     * (won) any row -- a stale or wrong token returns false without changing anything.
     */
    public function releaseLogicalDispatch(
        string $gateway,
        string $logicalEventKey,
        string $leaseToken,
    ): bool;
}
