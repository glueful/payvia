<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Services;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Events\ProviderEvent;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\CheckoutOriginationRepository;
use Glueful\Extensions\Payvia\Repositories\CheckoutSubjectGuardRepository;
use Glueful\Extensions\Payvia\Repositories\ProviderCorrelationRepository;

final class GatewaySubscriptionService
{
    /**
     * The origination ledger's `consumer_metadata` correlation fields (design spec §3.4) that
     * are ever merged into a correlated event's normalized metadata. `actor_user_uuid` is
     * DELIBERATELY absent -- it never leaves the ledger/audit record.
     *
     * @var list<string>
     */
    private const ORIGINATION_ENRICHMENT_FIELDS = [
        'tenant_uuid',
        'subject_type',
        'subject_uuid',
        'plan_uuid',
        'glueful_consumer',
    ];

    /**
     * The origination repository and the subject guard repository MUST share the same
     * `Connection` instance: {@see attemptLateSettlementConflict()} wraps the guard block and
     * the ledger transition in one transaction on that shared connection (mirroring
     * `SubscriptionCheckoutService`'s identical constructor-time assertion) so the two writes
     * either both land or neither does -- a partial write would otherwise strand the guard
     * `blocked` while the ledger never actually reached `late_settlement_conflict`.
     */
    public function __construct(
        private ApplicationContext $context,
        private ProviderCorrelationRepository $subscriptions,
        private GatewayManager $gateways,
        private CheckoutOriginationRepository $originations,
        private CheckoutSubjectGuardRepository $guards,
    ) {
        if ($this->originations->getConnection() !== $this->guards->getConnection()) {
            throw new \LogicException(
                'GatewaySubscriptionService requires the origination repository and the subject '
                    . 'guard repository to share the SAME Connection instance -- the '
                    . 'late-settlement-conflict guard-block + ledger-transition sequence cannot be '
                    . 'made atomic across two different connections.'
            );
        }
    }

    /**
     * Resolve subscription ownership before persisting, in the binding order the spec
     * requires under the global (gateway, gateway_subscription_id) identity:
     *
     * 1. Existing projection: its persisted `tenant_uuid` is authoritative. The update is
     *    always owner-qualified (see `ProviderCorrelationRepository::upsertGatewaySubscription`),
     *    so a conflicting metadata tenant hint can never move it -- it is diagnosed and ignored.
     *    "Existing wins" is NOT an early return when the event also carries a resolvable
     *    origination-ledger token: the ledger owner must still MATCH the existing owner (a
     *    disagreement refuses the whole event -- {@see UnresolvedSubscriptionOwnershipException
     *    ::originationOwnerMismatch()}), then the same idempotent ledger transition + enrichment
     *    below runs WITHOUT moving ownership. This closes the crash window where the provider
     *    row was written but normalized-payload persistence failed before first dispatch.
     * 2. Origination ledger (design spec §3.4): resolved by the `origination_uuid` token Stripe's
     *    normalizer promotes into normalized metadata. Found with no existing projection ->
     *    adopt ITS `tenant_uuid` (never the provider-supplied metadata hint alone -- a
     *    conflicting hint is diagnosed and ignored, matching rule 1's existing hint policy),
     *    record `provider_subscription_id`, and transition to `provider_observed` (or, when the
     *    subject's guard is already `live` for a NEWER origination, to `late_settlement_conflict`
     *    instead -- see {@see correlateOriginationAndEnrich()}). Either way, the ledger row's
     *    `consumer_metadata` correlation fields are merged into the event's normalized metadata
     *    and the REPLACEMENT event is returned so Task 6's `WebhookService` plumbing persists and
     *    dispatches it on this same first delivery.
     * 3. First projection (no origination match): the tenant is derived from the globally unique
     *    `billing_plan_uuid` carried in normalized provider metadata, re-read via the correlation
     *    surface. An explicit metadata `tenant_uuid` hint must equal that plan's owner or the
     *    event is rejected.
     * 4. No existing projection and no valid local plan correlation: fail closed. No sentinel
     *    row is ever written; `UnresolvedSubscriptionOwnershipException` propagates so
     *    `WebhookService` records the provider event failed/retryable.
     *
     * Separately, `checkout.session.expired` (Stripe) is recognized and handled BEFORE any of
     * the above: it is a ledger LIFECYCLE event, never a subscription projection event -- see
     * {@see handleCheckoutExpired()}.
     */
    public function applyProviderEvent(PaymentProviderEventInterface $event): ?PaymentProviderEventInterface
    {
        if ($event->type() === EventType::CHECKOUT_EXPIRED) {
            $this->handleCheckoutExpired($event);
            return null;
        }

        if (!$this->isSubscriptionEvent($event->type())) {
            return null;
        }

        $normalized = $event->normalized();
        $gatewaySubscriptionId = $normalized['gateway_subscription_id'] ?? null;
        if (!is_scalar($gatewaySubscriptionId) || (string) $gatewaySubscriptionId === '') {
            return null;
        }

        $gateway = $event->gateway();
        $gatewaySubscriptionId = (string) $gatewaySubscriptionId;
        $hint = $this->metadataTenantHint($normalized);
        $row = $this->rowFromNormalized($gateway, $gatewaySubscriptionId, $normalized, $event->raw());
        $origination = $this->resolveOrigination($gateway, $normalized);

        $existing = $this->subscriptions->findGatewaySubscriptionByGatewayId($gateway, $gatewaySubscriptionId);
        if ($existing !== null) {
            $owner = (string) ($existing['tenant_uuid'] ?? '');
            if ($hint !== null && $hint !== $owner) {
                $this->diagnoseOwnershipHintMismatch($gateway, $gatewaySubscriptionId, $owner, $hint);
            }

            // Owner-qualified update: any tenant_uuid this payload carries is discarded by the
            // repository, so ownership cannot move here regardless of the hint above.
            $this->subscriptions->upsertGatewaySubscription($row);

            if ($origination === null) {
                return null;
            }

            $originationOwner = (string) ($origination['tenant_uuid'] ?? '');
            if ($originationOwner !== $owner) {
                throw UnresolvedSubscriptionOwnershipException::originationOwnerMismatch(
                    $gateway,
                    $gatewaySubscriptionId,
                    (string) $origination['uuid'],
                    $originationOwner,
                    $owner
                );
            }

            return $this->correlateOriginationAndEnrich($event, $origination, $gatewaySubscriptionId);
        }

        if ($origination !== null) {
            $ownerTenant = (string) ($origination['tenant_uuid'] ?? '');
            if ($hint !== null && $hint !== $ownerTenant) {
                $this->diagnoseOwnershipHintMismatch($gateway, $gatewaySubscriptionId, $ownerTenant, $hint);
            }

            $row['tenant_uuid'] = $ownerTenant;
            $this->subscriptions->upsertGatewaySubscription($row);

            return $this->correlateOriginationAndEnrich($event, $origination, $gatewaySubscriptionId);
        }

        $planUuid = $this->stringOrNull($normalized['billing_plan_uuid'] ?? null);
        $plan = $planUuid !== null ? $this->subscriptions->findBillingPlanByUuid($planUuid) : null;
        if ($plan === null) {
            throw UnresolvedSubscriptionOwnershipException::noPlanCorrelation(
                $gateway,
                $gatewaySubscriptionId,
                $planUuid
            );
        }

        $owner = (string) ($plan['tenant_uuid'] ?? '');
        if ($hint !== null && $hint !== $owner) {
            throw UnresolvedSubscriptionOwnershipException::metadataTenantMismatch(
                $gateway,
                $gatewaySubscriptionId,
                $hint,
                $owner
            );
        }

        $row['tenant_uuid'] = $owner;
        $this->subscriptions->upsertGatewaySubscription($row);

        return null;
    }

    /**
     * Stripe's `checkout.session.expired` webhook (design spec §3.3) is a ledger LIFECYCLE
     * event: it resolves the origination by `checkout_reference` (the session id Stripe's
     * normalizer promotes to `normalized['reference']`), transitions `pending -> expired`, and
     * releases the matching subject guard -- all BEFORE the ordinary isSubscriptionEvent() gate,
     * since this is never a subscription projection event and must never reach the
     * gateway_subscriptions projection or the origination-ledger correlation below.
     *
     * An unresolvable reference, an unknown origination, or an origination that is no longer
     * legally transitionable `-> expired` (e.g. it already correlated via a real webhook and is
     * `provider_observed`/terminal-for-another-reason) is silently ignored -- `transition()`'s
     * own CAS refuses the illegal jump without writing anything, and a redelivery of an
     * already-`expired` origination is the idempotent no-op `transition()` already guarantees.
     */
    private function handleCheckoutExpired(PaymentProviderEventInterface $event): void
    {
        $normalized = $event->normalized();
        $checkoutReference = $this->stringOrNull($normalized['reference'] ?? null);
        if ($checkoutReference === null) {
            return;
        }

        $origination = $this->originations->findByCheckoutReference($event->gateway(), $checkoutReference);
        if ($origination === null) {
            return;
        }

        $uuid = (string) $origination['uuid'];
        if (!$this->originations->transition($this->context, $uuid, (string) $origination['status'], 'expired')) {
            return;
        }

        $this->guards->release(
            $this->context,
            (string) ($origination['tenant_uuid'] ?? ''),
            (string) ($origination['subject_key'] ?? ''),
            $uuid,
        );
    }

    /**
     * Resolve the origination ledger row an event's `origination_uuid` normalized metadata
     * token points at (design spec §3.4 step 1-2). The gateway MUST match -- a token that
     * happens to name a row belonging to a different gateway is treated exactly like no match at
     * all, falling through to the remaining ownership sources rather than ever cross-gateway
     * correlating.
     *
     * @param array<string,mixed> $normalized
     * @return array<string,mixed>|null
     */
    private function resolveOrigination(string $gateway, array $normalized): ?array
    {
        $originationUuid = $this->stringOrNull($normalized['origination_uuid'] ?? null);
        if ($originationUuid === null) {
            return null;
        }

        $origination = $this->originations->findByUuid($originationUuid);
        if ($origination === null || (string) $origination['gateway'] !== $gateway) {
            return null;
        }

        return $origination;
    }

    /**
     * Advances the correlated origination ledger row and returns the enriched replacement event
     * (design spec §3.4). Called identically whether this is the FIRST correlation (no existing
     * projection -- ownership is being adopted) or a REPLAY against an already-matching existing
     * projection (ownership never moves, only the idempotent ledger transition + enrichment
     * repeat).
     *
     * Already `late_settlement_conflict` (design spec §3.3, "never retries the same impossible
     * relink forever" + §3.6's conflict-ack contract): this is a STABLE terminal state --
     * `CheckoutOriginationRepository::TRANSITIONS['late_settlement_conflict']` is permanently
     * empty, so EVERY further transition attempt from here is illegal by design, forever. Stripe
     * echoes subscription metadata for the subscription's whole lifetime, so every future
     * `customer.subscription.*` for this same `gateway_subscription_id` would otherwise
     * re-resolve this exact row and fail closed on every single delivery. This branch short-
     * circuits BEFORE any transition/guard write is even attempted: no writes, guard left exactly
     * as it is (Task 9's operator reconciliation is the only path that ever moves it again), just
     * enrich + dispatch so the subscriptions consumer keeps rejecting deterministically.
     *
     * Late-settlement conflict, the FIRST time (design spec §3.3): when the origination is
     * already in some OTHER TERMINAL status AND the subject's guard is currently `live` for a
     * DIFFERENT (newer) origination, this is a late webhook for a historical attempt that has
     * already been superseded. It transitions to `late_settlement_conflict` instead of
     * `provider_observed` and BLOCKS the guard -- bound to THIS conflicted origination's uuid
     * (not the newer one), per Task 4's persisted-binding semantics, so Task 9's operator-
     * reconciliation CAS resolves against the exact origination the conflict was raised for. Both
     * writes happen atomically -- see {@see attemptLateSettlementConflict()}. The newer
     * origination's own row is never read for mutation and is left completely untouched. The
     * event is still enriched and returned for normal dispatch -- the downstream subscriptions
     * consumer is expected to reject the mismatched reservation deterministically
     * (`origination_mismatch`); this method never decides that outcome itself.
     *
     * A terminal origination with NO newer live owner regresses straight to `provider_observed`
     * (the one other sanctioned terminal transition) -- e.g. a Stripe checkout that already
     * expired/failed locally, but the money actually moved and no other reservation has since
     * claimed the subject. That single write is checked, never fired and forgotten -- see
     * {@see transitionOrThrow()}.
     *
     * @param array<string,mixed> $origination
     */
    private function correlateOriginationAndEnrich(
        PaymentProviderEventInterface $event,
        array $origination,
        string $gatewaySubscriptionId,
    ): PaymentProviderEventInterface {
        $uuid = (string) $origination['uuid'];
        $currentStatus = (string) $origination['status'];
        $tenantUuid = (string) ($origination['tenant_uuid'] ?? '');
        $subjectKey = (string) ($origination['subject_key'] ?? '');

        if ($currentStatus === 'late_settlement_conflict') {
            return $this->enrichWithOrigination($event, $origination);
        }

        if (CheckoutOriginationRepository::isTerminalStatus($currentStatus)) {
            $conflicted = $this->attemptLateSettlementConflict(
                $uuid,
                $currentStatus,
                $tenantUuid,
                $subjectKey,
                $gatewaySubscriptionId
            );

            if ($conflicted) {
                return $this->enrichWithOrigination($event, $origination);
            }
        }

        // Either not terminal (the ordinary forward-or-idempotent-replay path), or terminal with
        // no newer owner (the one other sanctioned terminal -> provider_observed regression).
        $this->transitionOrThrow($uuid, $currentStatus, 'provider_observed', $gatewaySubscriptionId);

        return $this->enrichWithOrigination($event, $origination);
    }

    /**
     * Attempts the late-settlement-conflict path ATOMICALLY (design spec §3.3, related hardening
     * finding): the guard block and the ledger transition either BOTH land or NEITHER does, in
     * one transaction over the connection the constructor already asserts `$originations` and
     * `$guards` share. Without this, a successful guard block followed by a REFUSED ledger
     * transition (a second concurrent write landing in between) would strand the guard `blocked`
     * while the ledger never actually reached `late_settlement_conflict` -- and since the
     * origination would then no longer BE terminal after that race, no future delivery would
     * ever revisit the guard to fix it.
     *
     * Returns `true` when the conflict was durably recorded (both writes committed), `false` when
     * there is genuinely no newer live owner (nothing was written; the transaction is rolled back
     * as a harmless no-op) -- the caller's signal to fall through to the terminal ->
     * `provider_observed` re-bind instead. A refused ledger transition after a successful guard
     * block rolls the guard block back too and rethrows -- never dispatches enrichment describing
     * a ledger state that was never actually persisted, the same contract
     * {@see transitionOrThrow()} enforces for the single-write path.
     */
    private function attemptLateSettlementConflict(
        string $uuid,
        string $currentStatus,
        string $tenantUuid,
        string $subjectKey,
        string $gatewaySubscriptionId,
    ): bool {
        $tx = $this->originations->getConnection()->getTransactionManager();
        $tx->begin();

        try {
            $newerOwner = $this->blockNewerOwnerOrRelease($tenantUuid, $subjectKey, $uuid, $gatewaySubscriptionId);
            if ($newerOwner === null) {
                $tx->rollback();
                return false;
            }

            $this->transitionOrThrow($uuid, $currentStatus, 'late_settlement_conflict', $gatewaySubscriptionId);
            $tx->commit();

            return true;
        } catch (\Throwable $e) {
            $tx->rollback();
            throw $e;
        }
    }

    /**
     * CAS the origination ledger's `status` and throw when refused (design spec §3.3/§3.4): a
     * refused CAS means the row is no longer at `$from` -- a concurrent write beat us to it --
     * and the caller must NEVER treat that as success (enriching/dispatching a ledger state that
     * was never actually persisted). Throwing rides `WebhookService::processStored()`'s existing
     * applier-failure handling (`markFailed()` + rethrow), leaving the provider event retryable,
     * exactly like {@see handleCheckoutExpired()}'s own guard against an illegal jump -- except
     * that method silently no-ops on refusal (a pure lifecycle event with nothing to enrich),
     * while this one is mid-correlation and must fail loudly instead.
     */
    private function transitionOrThrow(string $uuid, string $from, string $to, string $gatewaySubscriptionId): void
    {
        $advanced = $this->originations->transition($this->context, $uuid, $from, $to, [
            'provider_subscription_id' => $gatewaySubscriptionId,
        ]);

        if (!$advanced) {
            throw new \RuntimeException(sprintf(
                'Payvia: failed to advance checkout origination %s from %s to %s for subscription %s '
                    . '-- the row no longer matched the expected status (concurrent write?).',
                $uuid,
                $from,
                $to,
                $gatewaySubscriptionId
            ));
        }
    }

    /**
     * Block the subject guard against the newer origination currently holding it `live`, closing
     * the TOCTOU window between reading the guard and writing it (design spec §3.3): the CAS
     * write ({@see CheckoutSubjectGuardRepository::blockIfBoundTo()}) only succeeds against the
     * EXACT newer origination just read. If it is refused -- the newer origination's own
     * finalizer released the guard in between (e.g. its checkout completed cleanly) -- this
     * re-reads ONCE and re-evaluates against the fresh state rather than force-overwriting a
     * guard that is no longer the conflict it appeared to be. A second consecutive refusal (the
     * guard kept moving under a concurrent write even after the re-read) is a hard error, exactly
     * like {@see transitionOrThrow()} -- never silently retried forever.
     *
     * Always called from inside {@see attemptLateSettlementConflict()}'s transaction: any write
     * this performs is provisional until that caller commits, and rolls back together with it if
     * the subsequent ledger transition is refused.
     *
     * Returns the newer origination's uuid the guard was actually blocked against, or null when
     * there is genuinely no newer LIVE owner (never was, or the race resolved it away) -- the
     * caller's signal to fall through to the terminal -> provider_observed re-bind instead of
     * conflicting.
     */
    private function blockNewerOwnerOrRelease(
        string $tenantUuid,
        string $subjectKey,
        string $conflictedUuid,
        string $gatewaySubscriptionId,
    ): ?string {
        $guard = $this->guards->findBySubject($this->context, $tenantUuid, $subjectKey);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $newerOwner = $this->liveOwnerOtherThan($guard, $conflictedUuid);
            if ($newerOwner === null) {
                return null;
            }

            $blocked = $this->guards->blockIfBoundTo(
                $this->context,
                $tenantUuid,
                $subjectKey,
                $newerOwner,
                $conflictedUuid,
                $this->lateSettlementReason($conflictedUuid, $gatewaySubscriptionId, $newerOwner)
            );

            if ($blocked) {
                return $newerOwner;
            }

            // Refused: the guard moved between our read and the CAS write. Re-read once and
            // re-evaluate against the fresh state rather than assuming the same conflict.
            $guard = $this->guards->findBySubject($this->context, $tenantUuid, $subjectKey);
        }

        throw new \RuntimeException(sprintf(
            'Payvia: could not block subject guard %s/%s against conflicted origination %s for '
                . 'subscription %s -- the guard kept moving under a concurrent write.',
            $tenantUuid,
            $subjectKey,
            $conflictedUuid,
            $gatewaySubscriptionId
        ));
    }

    /** @param array<string,mixed>|null $guard */
    private function liveOwnerOtherThan(?array $guard, string $excludeUuid): ?string
    {
        if ($guard === null || (string) ($guard['state'] ?? '') !== 'live') {
            return null;
        }

        $originationUuid = (string) ($guard['origination_uuid'] ?? '');

        return $originationUuid !== '' && $originationUuid !== $excludeUuid ? $originationUuid : null;
    }

    private function lateSettlementReason(
        string $originationUuid,
        string $gatewaySubscriptionId,
        string $newerOriginationUuid,
    ): string {
        return sprintf(
            'late_settlement_conflict: origination %s observed provider subscription %s after origination '
                . '%s already owns this subject',
            $originationUuid,
            $gatewaySubscriptionId,
            $newerOriginationUuid
        );
    }

    /**
     * Merge the origination's `consumer_metadata` correlation fields into the event's normalized
     * `metadata` (design spec §3.4 step 3) and return the replacement -- `actor_user_uuid` is
     * NEVER merged; it stays in the ledger/audit record only. Provider-carried metadata keys
     * (there should be none per §3.5's closed metadata policy, but a hostile/malformed payload
     * is not trusted either way) are overwritten by the ledger's own values, which are
     * authoritative here.
     *
     * @param array<string,mixed> $origination
     */
    private function enrichWithOrigination(
        PaymentProviderEventInterface $event,
        array $origination,
    ): PaymentProviderEventInterface {
        // `withNormalized()` is deliberately NOT part of PaymentProviderEventInterface (Task 6):
        // it is `ProviderEvent`'s own immutable-replacement seam. `ProviderEvent` is the sole
        // production implementation (every gateway's parseWebhookEvent()/fromStored() path
        // returns one), so this is a defensive fail-fast rather than an expected branch.
        if (!$event instanceof ProviderEvent) {
            throw new \LogicException(sprintf(
                '%s: origination enrichment requires a %s instance, got %s.',
                self::class,
                ProviderEvent::class,
                get_debug_type($event)
            ));
        }

        $consumerMetadata = $origination['consumer_metadata'] ?? null;
        $consumerMetadata = is_array($consumerMetadata) ? $consumerMetadata : [];

        $correlation = [];
        foreach (self::ORIGINATION_ENRICHMENT_FIELDS as $field) {
            if (array_key_exists($field, $consumerMetadata)) {
                $correlation[$field] = $consumerMetadata[$field];
            }
        }

        $normalized = $event->normalized();
        $existingMetadata = isset($normalized['metadata']) && is_array($normalized['metadata'])
            ? $normalized['metadata']
            : [];
        $normalized['metadata'] = array_merge($existingMetadata, $correlation);

        return $event->withNormalized($normalized);
    }

    /**
     * Reconcile a subscription against the provider by the global (gateway,
     * gateway_subscription_id) locator and return the existing owner's refreshed row.
     *
     * Reconciliation uses the same global locator as webhook ownership resolution and
     * returns the existing owner; it never adopts or moves a projection. An id with no
     * persisted projection has no owner to reconcile against, so this must not create one --
     * unlike `applyProviderEvent()`, there is no correlated billing plan here to derive a
     * tenant from, so inserting would always land a sentinel (`tenant_uuid` = '') row. The
     * existence check runs before the provider fetch so an unknown id never calls out to the
     * gateway at all.
     *
     * @return array<string,mixed>|null The existing projection's refreshed row, or null when
     *                                   no projection exists for this id.
     */
    public function reconcile(string $gateway, string $gatewaySubscriptionId): ?array
    {
        $existing = $this->subscriptions->findGatewaySubscriptionByGatewayId($gateway, $gatewaySubscriptionId);
        if ($existing === null) {
            return null;
        }

        $driver = $this->gateways->subscriptionGateway($gateway);
        $raw = $driver->fetchSubscription($gatewaySubscriptionId);
        $normalized = $this->normalizeProviderSubscription($gateway, $raw);
        $this->subscriptions->upsertGatewaySubscription($this->rowFromNormalized(
            $gateway,
            $gatewaySubscriptionId,
            $normalized,
            $raw
        ));

        return $this->subscriptions->findGatewaySubscriptionByGatewayId($gateway, $gatewaySubscriptionId);
    }

    /**
     * @param array<string,mixed> $normalized
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function rowFromNormalized(
        string $gateway,
        string $gatewaySubscriptionId,
        array $normalized,
        array $raw,
    ): array {
        $row = [
            'gateway' => $gateway,
            'gateway_subscription_id' => $gatewaySubscriptionId,
        ];

        if (array_key_exists('status', $normalized)) {
            $row['status'] = $this->normalizeStatus($this->stringOrNull($normalized['status']));
        }

        foreach (
            [
            'gateway_customer_id',
            'gateway_price_id',
            'billing_plan_uuid',
            'current_period_start',
            'current_period_end',
            'canceled_at',
            ] as $key
        ) {
            $value = $this->stringOrNull($normalized[$key] ?? null);
            if ($value !== null) {
                $row[$key] = $value;
            }
        }

        if (array_key_exists('cancel_at_period_end', $normalized)) {
            $row['cancel_at_period_end'] = (bool) $normalized['cancel_at_period_end'];
        }

        if (isset($normalized['metadata']) && is_array($normalized['metadata'])) {
            $row['metadata'] = $normalized['metadata'];
        }

        if (config($this->context, 'payvia.features.store_raw_payload', true)) {
            $row['raw_payload'] = $raw;
        }

        return $row;
    }

    /**
     * Normalize a provider's raw subscription fetch into the shape consumed by
     * rowFromNormalized(). Different gateways return very different shapes (e.g.
     * Stripe returns the raw subscription object with unix-timestamp period
     * fields, whereas Paystack wraps data under 'data' with a date-string
     * next_payment_date), so normalization is gateway-aware.
     *
     * Unknown gateways fall back to the generic (Paystack-shaped) normalizer so
     * third-party subscription drivers continue to work.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function normalizeProviderSubscription(string $gateway, array $raw): array
    {
        return match ($gateway) {
            'stripe' => $this->normalizeStripeSubscription($raw),
            default => $this->normalizeGenericSubscription($raw),
        };
    }

    /**
     * Generic / Paystack-shaped normalization. Behaves exactly as the historical
     * single-track normalizer.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function normalizeGenericSubscription(array $raw): array
    {
        $data = (array) ($raw['data'] ?? $raw);
        $customer = (array) ($data['customer'] ?? []);
        $plan = (array) ($data['plan'] ?? []);

        return [
            'gateway_subscription_id' => $data['subscription_code'] ?? $data['id'] ?? null,
            'gateway_customer_id' => $customer['customer_code'] ?? $data['customer_code'] ?? null,
            'gateway_price_id' => $plan['plan_code'] ?? $data['plan_code'] ?? null,
            'billing_plan_uuid' => $data['billing_plan_uuid'] ?? null,
            // Do not fabricate 'active' when the provider omits a status; an
            // absent status normalizes to 'unknown' (fail closed) downstream.
            'status' => $data['status'] ?? null,
            'current_period_end' => $data['next_payment_date'] ?? null,
            'cancel_at_period_end' => (bool) ($data['cancel_at_period_end'] ?? false),
            'canceled_at' => $data['canceled_at'] ?? null,
            'metadata' => isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : null,
        ];
    }

    /**
     * Stripe-shaped normalization. Stripe's subscription fetch returns the raw
     * subscription object (no 'data' wrapper): the customer is a scalar id, the
     * price lives at items.data[0].price.id, and the period/cancellation fields
     * are unix timestamps that must be converted to 'Y-m-d H:i:s' before they
     * reach the DATETIME columns. Status is passed through raw — normalizeStatus
     * handles the mapping (and fails closed when absent).
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function normalizeStripeSubscription(array $raw): array
    {
        $price = (array) (((array) ($raw['items']['data'] ?? []))[0]['price'] ?? []);
        $metadata = isset($raw['metadata']) && is_array($raw['metadata']) ? $raw['metadata'] : null;
        $billingPlanUuid = $metadata !== null && isset($metadata['billing_plan_uuid'])
            && is_scalar($metadata['billing_plan_uuid'])
            ? (string) $metadata['billing_plan_uuid']
            : null;

        return [
            'gateway_subscription_id' => $raw['id'] ?? null,
            'gateway_customer_id' => isset($raw['customer']) && is_scalar($raw['customer'])
                ? (string) $raw['customer']
                : null,
            'gateway_price_id' => isset($price['id']) && is_scalar($price['id']) ? (string) $price['id'] : null,
            'billing_plan_uuid' => $billingPlanUuid,
            // Pass status through raw; normalizeStatus maps it and fails closed
            // when absent (never fabricating 'active').
            'status' => $raw['status'] ?? null,
            'current_period_start' => $this->unixToDateTime($raw['current_period_start'] ?? null),
            'current_period_end' => $this->unixToDateTime($raw['current_period_end'] ?? null),
            'canceled_at' => $this->unixToDateTime($raw['canceled_at'] ?? null),
            'cancel_at_period_end' => (bool) ($raw['cancel_at_period_end'] ?? false),
            'metadata' => $metadata,
        ];
    }

    private function unixToDateTime(mixed $value): ?string
    {
        if (!is_numeric($value)) {
            return null;
        }

        return (new \DateTimeImmutable('@' . (string) $value))->format('Y-m-d H:i:s');
    }

    private function isSubscriptionEvent(string $type): bool
    {
        return in_array($type, [
            EventType::SUBSCRIPTION_CREATED,
            EventType::SUBSCRIPTION_UPDATED,
            EventType::SUBSCRIPTION_PAST_DUE,
            EventType::SUBSCRIPTION_CANCELED,
        ], true);
    }

    private function normalizeStatus(?string $status): string
    {
        // Fail closed: only explicitly known active-ish statuses become 'active'.
        // Any unrecognized, future, or empty status maps to 'unknown' so that a
        // delinquent/paused/expired provider subscription is never treated as live.
        return match (strtolower((string) $status)) {
            'active', 'trialing' => 'active',
            'past_due', 'attention', 'payment_failed', 'unpaid' => 'past_due',
            'canceled', 'cancelled', 'disabled', 'not_renew', 'not_renewing',
            'non-renewing', 'incomplete_expired' => 'canceled',
            'incomplete', 'pending' => 'incomplete',
            'paused' => 'paused',
            default => 'unknown',
        };
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * A metadata `tenant_uuid` hint is never an ownership authority by itself -- it is only
     * ever compared against an already-resolved owner (the existing projection's own tenant,
     * or the correlated billing plan's owner) and rejected on disagreement.
     *
     * @param array<string,mixed> $normalized
     */
    private function metadataTenantHint(array $normalized): ?string
    {
        $metadata = $normalized['metadata'] ?? null;
        if (!is_array($metadata)) {
            return null;
        }

        return $this->stringOrNull($metadata['tenant_uuid'] ?? null);
    }

    /**
     * Rule 1 never obeys a conflicting ownership hint, but the attempt is still worth
     * surfacing to operators -- log it (best-effort PSR-3, falling back to error_log the same
     * way Payvia's controllers already do) rather than silently dropping it.
     */
    private function diagnoseOwnershipHintMismatch(
        string $gateway,
        string $gatewaySubscriptionId,
        string $owner,
        string $hint,
    ): void {
        $message = sprintf(
            '[Payvia] subscription ownership hint mismatch ignored: %s subscription %s is owned by '
                . 'tenant "%s" but the provider metadata carried a conflicting tenant_uuid hint "%s"; the '
                . 'existing owner is authoritative and the hint was never obeyed.',
            $gateway,
            $gatewaySubscriptionId,
            $owner,
            $hint
        );

        try {
            app($this->context, \Psr\Log\LoggerInterface::class)->warning($message, [
                'gateway' => $gateway,
                'gateway_subscription_id' => $gatewaySubscriptionId,
                'owner_tenant_uuid' => $owner,
                'hint_tenant_uuid' => $hint,
            ]);
        } catch (\Throwable) {
            error_log($message);
        }
    }
}
