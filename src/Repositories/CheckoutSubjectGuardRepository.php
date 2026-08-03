<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Repositories;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\Repositories\Concerns\DetectsUniqueViolations;
use Glueful\Helpers\Utils;
use Glueful\Repository\BaseRepository;

/**
 * The live-guard authority (design spec §3.3): "may this subject originate another checkout
 * right now?" is answered ENTIRELY by this table's `state` column -- never by an origination
 * row's own `status`, and never by any local TTL (a hosted checkout may complete well after a
 * client-side expectation of expiry has passed).
 *
 * `state` is one of `open` (free to originate), `live` (exclusively bound to one origination),
 * or `blocked` (an operator-remediation hold, e.g. a late_settlement_conflict -- never
 * automatically reopened; only {@see reopen()}'s explicit, origination-CAS'd operator
 * reconciliation path ever clears it). A guard row for a subject may not exist yet at all before
 * its first-ever claim; {@see lockAndClaim()} handles both the "claim an existing open row" and
 * "insert the first-ever row directly as live" paths, guarding the latter's concurrent-first-claim
 * race with a savepoint so a losing INSERT's failed statement can never poison an ambient
 * PostgreSQL transaction for the re-read that follows it.
 */
final class CheckoutSubjectGuardRepository extends BaseRepository
{
    use DetectsUniqueViolations;

    private const TABLE = 'subscription_checkout_subject_guards';

    public function getTableName(): string
    {
        return self::TABLE;
    }

    /**
     * Claim exclusive `live` ownership of (tenant, subject) for `$originationUuid`. Succeeds
     * exactly once per "generation" of the guard: idempotent when this SAME origination already
     * holds it (a retried caller sees the same success it would have gotten the first time),
     * refused when a DIFFERENT origination holds it or the guard is `blocked`.
     */
    public function lockAndClaim(
        ApplicationContext $context,
        string $tenantUuid,
        string $subjectKey,
        string $originationUuid,
    ): bool {
        if ($tenantUuid === '' || $subjectKey === '' || $originationUuid === '') {
            return false;
        }

        $now = $this->now();

        $affected = $this->db->table(self::TABLE)->executeModification(
            'UPDATE ' . self::TABLE . ' '
                . 'SET state = ?, origination_uuid = ?, revision = revision + 1, updated_at = ? '
                . 'WHERE tenant_uuid = ? AND subject_key = ? AND state = ?',
            ['live', $originationUuid, $now, $tenantUuid, $subjectKey, 'open'],
        );

        if ($affected > 0) {
            return true;
        }

        $existing = $this->findGuard($tenantUuid, $subjectKey);
        if ($existing !== null) {
            // Idempotent no-op: this same origination already won the claim (a retried call
            // observing the row it itself just wrote). Anything else -- a different origination
            // holding `live`, or `blocked` -- is a genuine refusal.
            return $existing['state'] === 'live' && $existing['origination_uuid'] === $originationUuid;
        }

        // No row exists yet for this subject at all: this is the first-ever claim. Concurrent
        // first claims race on the (tenant_uuid, subject_key) unique index -- wrap the insert in
        // a savepoint so a losing statement's failure can be cleanly rolled back (rather than
        // poisoning an ambient PostgreSQL transaction) before re-reading the winner.
        $tx = $this->db->getTransactionManager();
        $tx->begin();
        try {
            $this->db->table(self::TABLE)->insert([
                'uuid' => Utils::generateNanoID(12),
                'tenant_uuid' => $tenantUuid,
                'subject_key' => $subjectKey,
                'state' => 'live',
                'origination_uuid' => $originationUuid,
                'blocked_reason' => null,
                'revision' => 1,
                'created_at' => $now,
            ]);
            $tx->commit();

            return true;
        } catch (\Throwable $e) {
            if (!$this->isUniqueViolation($e)) {
                $tx->rollback();
                throw $e;
            }

            $tx->rollback();
            $winner = $this->findGuard($tenantUuid, $subjectKey);

            return $winner !== null
                && $winner['state'] === 'live'
                && $winner['origination_uuid'] === $originationUuid;
        }
    }

    /**
     * Release `live` ownership back to `open`. A CAS on the BOUND origination: only the
     * origination currently holding the guard may release it. Idempotent when the guard is
     * already `open` (a retried release); refused (no write) against a mismatched origination or
     * a `blocked` guard.
     */
    public function release(
        ApplicationContext $context,
        string $tenantUuid,
        string $subjectKey,
        string $originationUuid,
    ): bool {
        if ($tenantUuid === '' || $subjectKey === '' || $originationUuid === '') {
            return false;
        }

        $affected = $this->db->table(self::TABLE)->executeModification(
            'UPDATE ' . self::TABLE . ' '
                . 'SET state = ?, origination_uuid = NULL, revision = revision + 1, updated_at = ? '
                . 'WHERE tenant_uuid = ? AND subject_key = ? AND state = ? AND origination_uuid = ?',
            ['open', $this->now(), $tenantUuid, $subjectKey, 'live', $originationUuid],
        );

        if ($affected > 0) {
            return true;
        }

        $existing = $this->findGuard($tenantUuid, $subjectKey);

        return $existing !== null && $existing['state'] === 'open';
    }

    /**
     * Force the guard into `blocked` -- an operator-remediation hold that no automatic path
     * reopens. Works from any current state (or no row at all) and always succeeds: an operator
     * may always tighten a hold, including re-blocking with an updated `$reason`.
     *
     * `$originationUuid` is PERSISTED into `origination_uuid` (not cleared): a
     * `late_settlement_conflict` block needs the binding to survive so operator reconciliation
     * (design spec §3.8) can later CAS `reopen()` against the exact origination it was blocked
     * for. Pass null only for a genuine no-origination operator hold (nothing to reopen against
     * later except via out-of-band operator action).
     */
    public function block(
        ApplicationContext $context,
        string $tenantUuid,
        string $subjectKey,
        ?string $originationUuid,
        string $reason,
    ): bool {
        if ($tenantUuid === '' || $subjectKey === '') {
            return false;
        }

        $now = $this->now();
        $affected = $this->db->table(self::TABLE)->executeModification(
            'UPDATE ' . self::TABLE . ' '
                . 'SET state = ?, origination_uuid = ?, blocked_reason = ?, revision = revision + 1, '
                . 'updated_at = ? WHERE tenant_uuid = ? AND subject_key = ?',
            ['blocked', $originationUuid, $reason, $now, $tenantUuid, $subjectKey],
        );

        if ($affected > 0) {
            return true;
        }

        // No row existed yet: insert directly as blocked, guarding the same concurrent-first-
        // write race (and re-checking) as lockAndClaim()'s no-row branch.
        $tx = $this->db->getTransactionManager();
        $tx->begin();
        try {
            $this->db->table(self::TABLE)->insert([
                'uuid' => Utils::generateNanoID(12),
                'tenant_uuid' => $tenantUuid,
                'subject_key' => $subjectKey,
                'state' => 'blocked',
                'origination_uuid' => $originationUuid,
                'blocked_reason' => $reason,
                'revision' => 1,
                'created_at' => $now,
            ]);
            $tx->commit();

            return true;
        } catch (\Throwable $e) {
            if (!$this->isUniqueViolation($e)) {
                $tx->rollback();
                throw $e;
            }

            // Lost the race for the first row: fall through to the plain update path against
            // whatever the winner just created.
            $tx->rollback();
            $affected = $this->db->table(self::TABLE)->executeModification(
                'UPDATE ' . self::TABLE . ' '
                    . 'SET state = ?, origination_uuid = ?, blocked_reason = ?, revision = revision + 1, '
                    . 'updated_at = ? WHERE tenant_uuid = ? AND subject_key = ?',
                ['blocked', $originationUuid, $reason, $now, $tenantUuid, $subjectKey],
            );

            return $affected > 0;
        }
    }

    /**
     * Task 9's operator-reconciliation CAS path (design spec §3.8): re-open a `blocked` (or
     * still-`live`) guard back to `open`, but ONLY against the exact `$originationUuid` it is
     * currently bound to -- a wrong origination, or a guard already `open`, changes nothing.
     * `live` is included alongside `blocked` because reconciliation may resolve a guard that
     * never actually got blocked (e.g. a stale `live` row an operator has independently confirmed
     * dead) using the SAME CAS-against-binding contract, rather than needing a second code path.
     *
     * Distinct from {@see release()}: `release()` is the ordinary, unprivileged happy-path
     * release (`live` only -- the normal "this checkout finished" case an origination itself
     * triggers). `reopen()` is the operator-reconciliation path and is the one Task 9's `resolve()`
     * calls, precisely because it must also clear a `blocked` hold, which `release()` deliberately
     * never touches. A guard with a NULL `origination_uuid` (a no-origination operator hold) can
     * never be reopened this way -- SQL equality never matches NULL, by design: that hold requires
     * a separate, explicit operator action instead of a caller merely guessing an origination.
     */
    public function reopen(
        ApplicationContext $context,
        string $tenantUuid,
        string $subjectKey,
        string $originationUuid,
    ): bool {
        if ($tenantUuid === '' || $subjectKey === '' || $originationUuid === '') {
            return false;
        }

        $affected = $this->db->table(self::TABLE)->executeModification(
            'UPDATE ' . self::TABLE . ' '
                . 'SET state = ?, origination_uuid = NULL, blocked_reason = NULL, revision = revision + 1, '
                . 'updated_at = ? '
                . 'WHERE tenant_uuid = ? AND subject_key = ? AND state IN (?, ?) AND origination_uuid = ?',
            ['open', $this->now(), $tenantUuid, $subjectKey, 'blocked', 'live', $originationUuid],
        );

        return $affected > 0;
    }

    /** @return array<string,mixed>|null */
    private function findGuard(string $tenantUuid, string $subjectKey): ?array
    {
        return $this->db->table(self::TABLE)
            ->where(['tenant_uuid' => $tenantUuid, 'subject_key' => $subjectKey])
            ->limit(1)
            ->first();
    }

    private function now(): string
    {
        return $this->db->getDriver()->formatDateTime();
    }
}
