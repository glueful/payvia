<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Checkout;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Payvia\Repositories\CheckoutOriginationRepository;
use Glueful\Extensions\Payvia\Repositories\CheckoutSubjectGuardRepository;

/**
 * Operator reconciliation (design spec §3.8): `projection_rejected`, `late_settlement_conflict`,
 * and a stuck Paystack `pending` origination are not dead ends. This service is the ONLY sanctioned
 * way to move one of those originations forward, using the SAME transaction discipline as
 * {@see \Glueful\Extensions\Payvia\Checkout\SubscriptionCheckoutService::prepare()}: one
 * transaction wraps the origination's own (idempotent) status/audit-note write, the matching
 * subject guard's CAS reopen, and the host's local-only continuation, rolling all three back
 * together the instant anything after the origination write throws.
 *
 * Exactly two explicit resolutions exist -- there is deliberately no third "ignore" option:
 * - `provider_confirmed_dead`: the operator confirms EXTERNALLY that no payment or subscription
 *   was ever created. Allowed ONLY when this ledger row has NEVER observed provider money/
 *   subscription state -- see {@see moneyObserved()}. The only reconcilable starting status that
 *   satisfies this is `pending` (a stuck checkout that never got a webhook, per
 *   {@see CheckoutOriginationRepository::TRANSITIONS}'s legal `pending -> abandoned` edge); it
 *   advances to `abandoned`. This is deliberately ANY gateway's stuck `pending` row, not only
 *   Paystack's -- nothing about the money-observed check or the `pending -> abandoned` edge is
 *   gateway-specific; Paystack is merely the motivating, most-likely-to-need-it case (Stripe's
 *   own `abandonSubscriptionCheckout()` capability can usually resolve this automatically).
 * - `provider_canceled_or_refunded`: the operator has already canceled/refunded on the provider
 *   side. Allowed ONLY when this ledger row DID observe provider money/subscription state. The
 *   reconcilable starting statuses that satisfy this are `projection_rejected` and
 *   `late_settlement_conflict`; both stay at their own status (a legal, vacuous `$from === $to`
 *   self-transition per `TRANSITIONS`) -- `late_settlement_conflict` has no further legal
 *   transition at all (it is permanently terminal), and `projection_rejected` must never be
 *   nudged back toward `provider_observed` by this path, which would misrepresent it as freshly
 *   (re)observed. Both keep their terminal status as the historical record; only the audit trail
 *   changes.
 *
 * Every other starting status -- including the already-successful `dispatched`, and the
 * mid-flight `preparing`/`initializing` -- is refused outright: reconciliation exists for STUCK
 * or REJECTED originations, never for ones already resolved by the normal pipeline.
 *
 * The audit note is persisted into `reconciliation_note` (bounded exactly like
 * `projection_reason`) alongside `reconciliation_resolution`/`reconciled_at` -- a column pair
 * dedicated to this service (see migration 011) rather than a reuse of `projection_reason`
 * itself: that column is the durable projection CONSUMER'S own committed receipt (design spec
 * §3.6), and this service's own NEVER-rule ("never rewrites a committed rejected ack receipt")
 * means it must never overwrite it.
 *
 * This service NEVER performs an automatic refund, NEVER rewrites a committed rejected receipt,
 * and NEVER activates a subscription -- it only opens the guard and hands control back to the
 * host's local-only continuation, which is responsible for releasing the exactly-bound
 * INCOMPLETE reservation and nothing more.
 */
final class CheckoutReconciliationService
{
    public const RESOLUTION_PROVIDER_CONFIRMED_DEAD = 'provider_confirmed_dead';
    public const RESOLUTION_PROVIDER_CANCELED_OR_REFUNDED = 'provider_canceled_or_refunded';

    /**
     * Statuses this service ever resolves. Anything else -- `preparing`, `initializing`,
     * `dispatched`, `failed`, `expired`, `abandoned` -- is refused via
     * {@see CheckoutReconciliationRefused::notReconcilableStatus()} before any write.
     */
    private const RECONCILABLE_STATUSES = ['pending', 'projection_rejected', 'late_settlement_conflict'];

    /**
     * Statuses that, on their own, already prove provider money/subscription state was observed
     * (design spec §3.3: both are only ever reached FROM `provider_observed`). Combined with a
     * non-null `provider_subscription_id` in {@see moneyObserved()} for the full definition.
     */
    private const MONEY_OBSERVED_STATUSES = ['provider_observed', 'dispatched', 'projection_rejected',
        'late_settlement_conflict'];

    /** `projection_reason`'s own bound (see that column's docblock); reused verbatim here. */
    private const MAX_NOTE_LENGTH = 255;

    /**
     * Deliberately takes NO `Connection` of its own -- identical reasoning to
     * {@see SubscriptionCheckoutService::__construct()}: the one-transaction guarantee is only
     * real if both repositories provably share the same connection this service derives its
     * transaction manager from.
     */
    public function __construct(
        private readonly CheckoutOriginationRepository $originations,
        private readonly CheckoutSubjectGuardRepository $guards,
    ) {
        if ($this->originations->getConnection() !== $this->guards->getConnection()) {
            throw new \LogicException(
                'CheckoutReconciliationService requires the origination repository and the subject '
                    . 'guard repository to share the SAME Connection instance -- resolve()\'s single '
                    . 'owning transaction cannot span two different connections.'
            );
        }
    }

    private function connection(): Connection
    {
        return $this->originations->getConnection();
    }

    /**
     * @param callable(string $originationUuid): void $releaseLocalReservation Invoked with the
     *   resolved origination's uuid alone -- Payvia has no notion of the host's "subject"/
     *   reservation type, so the host's own closure is responsible for locating and releasing
     *   whatever it exactly bound to this origination (mirrors
     *   `SubscriptionService::releaseCheckoutReservation(Subject, originationUuid)`, design spec
     *   §4.1, which the host is expected to call from inside this closure).
     */
    public function resolve(
        ApplicationContext $context,
        string $originationUuid,
        string $resolution,
        string $auditNote,
        callable $releaseLocalReservation,
    ): void {
        if (
            $resolution !== self::RESOLUTION_PROVIDER_CONFIRMED_DEAD
            && $resolution !== self::RESOLUTION_PROVIDER_CANCELED_OR_REFUNDED
        ) {
            // Also where a bare `ignore` (or any other string) is refused -- there is no third
            // resolution, ever.
            throw CheckoutReconciliationRefused::unknownResolution($resolution);
        }

        $note = trim($auditNote);
        if ($note === '') {
            throw CheckoutReconciliationRefused::emptyAuditNote($originationUuid);
        }

        $row = $this->originations->findByUuid($originationUuid);
        if ($row === null) {
            throw CheckoutReconciliationRefused::unknownOrigination($originationUuid);
        }

        $status = (string) $row['status'];
        if (!in_array($status, self::RECONCILABLE_STATUSES, true)) {
            throw CheckoutReconciliationRefused::notReconcilableStatus($originationUuid, $status);
        }

        $moneyObserved = $this->moneyObserved($row);
        $confirmedDead = $resolution === self::RESOLUTION_PROVIDER_CONFIRMED_DEAD;
        if ($confirmedDead && $moneyObserved) {
            throw CheckoutReconciliationRefused::moneyAlreadyObserved($originationUuid);
        }
        if (!$confirmedDead && !$moneyObserved) {
            throw CheckoutReconciliationRefused::moneyNeverObserved($originationUuid);
        }

        // `pending -> abandoned` is the sole status CHANGE this service ever makes, and ONLY
        // when money was never observed -- i.e. only for `provider_confirmed_dead` (the money-
        // observed guard above already refuses `provider_confirmed_dead` whenever it WAS
        // observed, so `!$moneyObserved` here is equivalent to "this is a confirmed_dead
        // resolution", spelled out explicitly rather than relied upon implicitly). A `pending`
        // row that DID observe money (defensive case: `provider_subscription_id` already set)
        // resolved via `provider_canceled_or_refunded` must NOT collapse to `abandoned` --
        // writing status=`abandoned` ("nothing happened") beside
        // resolution=`provider_canceled_or_refunded` ("something happened and was undone") would
        // be a self-contradictory permanent record. Every other reconcilable status stays exactly
        // where it is -- a legal, vacuous `$from === $to` self-transition that writes only the
        // audit columns.
        $targetStatus = ($status === 'pending' && !$moneyObserved) ? 'abandoned' : $status;

        $tenantUuid = (string) $row['tenant_uuid'];
        $subjectKey = (string) $row['subject_key'];

        $tx = $this->connection()->getTransactionManager();
        $tx->begin();
        try {
            $transitioned = $this->originations->transition($context, $originationUuid, $status, $targetStatus, [
                'reconciliation_resolution' => $resolution,
                'reconciliation_note' => mb_substr($note, 0, self::MAX_NOTE_LENGTH),
                'reconciled_at' => $this->now(),
            ]);
            if (!$transitioned) {
                // The row moved under a concurrent write between our read and this CAS -- refuse
                // rather than silently applying a resolution against stale state.
                throw CheckoutReconciliationRefused::transitionRaced($originationUuid);
            }

            if (!$this->guards->reopen($context, $tenantUuid, $subjectKey, $originationUuid)) {
                // `block()` is unconditional -- a second block with a DIFFERENT origination can
                // overwrite the binding this call expected. Surface that as a refusal, not a
                // crash: reopen()'s CAS simply found no matching (state, origination) pair.
                throw CheckoutReconciliationRefused::guardBindingMoved($originationUuid, $subjectKey);
            }

            $releaseLocalReservation($originationUuid);

            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollback();
            throw $e;
        }
    }

    /** @param array<string,mixed> $row */
    private function moneyObserved(array $row): bool
    {
        if (($row['provider_subscription_id'] ?? null) !== null) {
            return true;
        }

        return in_array((string) $row['status'], self::MONEY_OBSERVED_STATUSES, true);
    }

    private function now(): string
    {
        return $this->connection()->getDriver()->formatDateTime();
    }
}
