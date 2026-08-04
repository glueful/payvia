<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Services;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\Checkout\RequiredProjectionAcknowledgementMissing;
use Glueful\Extensions\Payvia\Contracts\LogicalDispatchLeaseRepositoryInterface;
use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
use Glueful\Extensions\Payvia\Contracts\ProviderEventPayloadUpdaterInterface;
use Glueful\Extensions\Payvia\Contracts\ProviderEventRepositoryInterface;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Events\PaymentProviderEvent;
use Glueful\Extensions\Payvia\Events\ProviderEvent;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\CheckoutOriginationRepository;
use Glueful\Extensions\Payvia\Repositories\CheckoutSubjectGuardRepository;

final class WebhookService
{
    /**
     * WebhookService remains the SOLE durable `provider_events` owner: `$dispatcher` is called
     * from `dispatch()` only after a delivery has been persisted and atomically claimed for its
     * logical key. Completion of that claim depends on which claim mechanism won it: when
     * `$logicalDispatchLeases` is present, `dispatch()` acquires an owner-fenced lease via
     * {@see LogicalDispatchLeaseRepositoryInterface::acquireLogicalDispatchLease()} and, on a
     * dispatcher failure, releases ONLY that lease (never legacy `markLogicalDispatched()`) before
     * rethrowing -- so the row goes straight back to `pending` and an immediate retry (no clock
     * manipulation, no waiting out `staleSeconds`) can reclaim and redispatch it. Completion on
     * success goes exclusively through the fenced `completeLogicalDispatch()`; the two APIs are
     * never mixed on the same row within this code path. When `$logicalDispatchLeases` is absent
     * (the default -- e.g. a custom `ProviderEventRepositoryInterface` implementation that
     * doesn't also implement the lease capability, or a direct constructor call from existing
     * code), `dispatch()` falls back to today's byte-identical claim/reclaim/mark flow: only
     * `markLogicalDispatched()` (run after `$dispatcher` returns without throwing) can ever mark
     * that logical dispatch done, and a dispatcher failure leaves the row stuck `dispatching`
     * until `relayPending()`'s stale-claim reclaim recovers it. `PayviaServiceProvider::
     * makeWebhookService()` composes `$dispatcher` from THREE steps run in order: ordinary local
     * `PaymentProviderEvent` delivery first (through the framework's fault-isolated
     * `EventService::dispatch()` -- a single bad listener there can never abort delivery or this
     * method), then the opt-in tagged strict lane (`Contracts\StrictPaymentEventListener`,
     * composed via `PayviaServiceProvider::composeStrictLane()`), then delegation to
     * `Events\ProviderChargebackDispatcher` for recognized dispute/chargeback types, always last.
     * Only the first step is fault-isolated; the strict lane and the chargeback dispatcher are
     * both uncaught here -- any exception from either propagates out of `dispatch()` (after the
     * lease-path release, if applicable) and leaves the logical dispatch unmarked, so the row
     * stays redispatchable via `relayPending()` (or, on the lease path, immediately retryable via
     * `processStored()`). An empty strict lane (no tagged listeners registered) makes this
     * three-step composition behaviorally identical to the original two-step one.
     *
     * `$originations`/`$guards` are the OPTIONAL post-dispatch finalizer capability (design spec
     * §3.6): when both are wired, `dispatch()` calls the private `finalizeOrigination()` for
     * every `subscription.created` delivery INSIDE the exact same try block as `$dispatcher`
     * itself -- after it returns without throwing, but still before the lease/claim is marked
     * complete. A finalizer failure is therefore handled byte-identically to a dispatcher
     * failure: the lease is released (or the claim left stale-reclaimable) and the exception
     * propagates, leaving the provider event retryable. Either constructor argument left `null`
     * (a host that never wired workspace checkout, or a test harness exercising WebhookService in
     * isolation) makes `finalizeOrigination()` a byte-identical no-op -- construction never
     * requires them.
     *
     * `$originations` and `$guards`, when BOTH present, MUST share the SAME `Connection` instance
     * -- mirroring `GatewaySubscriptionService`'s identical constructor-time assertion (Task 7).
     * `completeOriginationDispatch()` wraps its `provider_observed -> dispatched` transition and
     * its guard release in ONE transaction on that shared connection, so the two writes either
     * both land or neither does; that atomicity would be a lie across two different connections.
     *
     * @param null|callable(PaymentProviderEvent):void $dispatcher
     * @param null|callable(PaymentProviderEventInterface):?PaymentProviderEventInterface $applier
     * @param null|callable(string):void $enqueue
     */
    public function __construct(
        private ApplicationContext $context,
        private GatewayManager $gateways,
        private ProviderEventRepositoryInterface $events,
        private $dispatcher = null,
        private $applier = null,
        private bool $queue = false,
        private $enqueue = null,
        private ?LogicalDispatchLeaseRepositoryInterface $logicalDispatchLeases = null,
        private ?ProviderEventPayloadUpdaterInterface $payloadUpdater = null,
        private ?CheckoutOriginationRepository $originations = null,
        private ?CheckoutSubjectGuardRepository $guards = null,
    ) {
        if (
            $this->originations !== null
            && $this->guards !== null
            && $this->originations->getConnection() !== $this->guards->getConnection()
        ) {
            throw new \LogicException(
                'WebhookService requires the origination repository and the subject guard '
                    . 'repository to share the SAME Connection instance -- the post-dispatch '
                    . 'finalizer\'s dispatched-transition + guard-release sequence cannot be made '
                    . 'atomic across two different connections.'
            );
        }
    }

    /** @param array<string,mixed> $headers */
    public function ingest(string $gatewayName, string $rawBody, array $headers = []): WebhookIngestResult
    {
        try {
            $gateway = $this->gateways->webhookGateway($gatewayName);
        } catch (\Throwable $e) {
            return new WebhookIngestResult(false, 404, message: $e->getMessage());
        }

        if (!$gateway->verifyWebhookSignature($rawBody, $headers)) {
            return new WebhookIngestResult(false, 401, message: 'invalid signature');
        }

        $event = $gateway->parseWebhookEvent($rawBody, $headers);
        $uuid = $this->recordEvent($event, 'webhook', true);
        if ($uuid === null) {
            $stored = $this->events->findByDeliveryKey($event->gateway(), $event->deliveryKey());
            $uuid = is_array($stored) ? (string) $stored['uuid'] : null;
        }

        if ($uuid === null) {
            return new WebhookIngestResult(true, 200, message: 'duplicate');
        }

        if ($this->queue && $this->enqueue !== null) {
            ($this->enqueue)($uuid);
            return new WebhookIngestResult(true, 202, $uuid, 'queued');
        }

        $this->processStored($uuid);
        return new WebhookIngestResult(true, 200, $uuid);
    }

    public function recordVerifyEvent(PaymentProviderEventInterface $event): ?string
    {
        $uuid = $this->recordEvent($event, 'verify', true);
        if ($uuid !== null) {
            $this->processStored($uuid);
        } else {
            $stored = $this->events->findByDeliveryKey($event->gateway(), $event->deliveryKey());
            if (
                is_array($stored)
                && ($stored['status'] ?? null) === 'processed'
                && ($stored['dispatch_status'] ?? null) !== 'dispatched'
            ) {
                $this->dispatch($this->reconstruct($stored), (string) $stored['uuid']);
            }
        }

        return $uuid;
    }

    /**
     * The applier (domain-effect application, e.g. `GatewaySubscriptionService::
     * applyProviderEvent()`) and the dispatch (delivery to local + contracts listeners) phases
     * are deliberately isolated from each other: an applier failure means processing never
     * completed, so the row is marked `failed` and a full retry (re-apply + re-dispatch) is
     * correct. A DISPATCH-phase failure -- e.g. a strict-dispatch chargeback listener throwing --
     * happens strictly AFTER `markProcessed()`, meaning processing/application already
     * succeeded; downgrading the row's `status` back to `failed` there would hide it from
     * `relayPending()`'s `findDispatchable()` query (`status = 'processed'` only), silently
     * breaking the "a listener failure leaves the row retryable and relayPending() redelivers
     * exactly once on success" guarantee. So a dispatch failure is left uncaught here: the row
     * stays `status='processed'`, only `dispatch_status` remains un-dispatched, and the
     * exception still propagates to the caller (e.g. `ingest()`).
     *
     * The applier may ALSO return a replacement `PaymentProviderEventInterface` -- built via
     * `ProviderEvent::withNormalized()` -- carrying enrichment discovered only while applying
     * the event (e.g. correlation data Task 7 resolves inside `GatewaySubscriptionService::
     * applyProviderEvent()`). A `null` return, including every existing void-returning applier
     * (which implicitly returns null), changes NOTHING: byte-identical to the pre-Task-6
     * behavior. A non-null return whose `normalized()` actually differs from the applied event's
     * is persisted via the additive `ProviderEventPayloadUpdaterInterface::
     * replaceNormalizedPayload()` capability -- resolved off the SAME `$events` instance exactly
     * like `$logicalDispatchLeases` is, so a custom `ProviderEventRepositoryInterface` that
     * doesn't also implement the updater capability simply gets `$payloadUpdater === null` here
     * -- BEFORE `markProcessed()`, so a crash between the write and the mark can never leave a
     * `processed` row pointing at stale metadata. A missing updater binding and a persistence
     * failure both fail exactly like an applier failure: `markFailed()` + rethrow, inside this
     * SAME try/catch -- because dispatching stale metadata after the applier already claimed
     * enrichment would be worse than simply retrying. Once persisted (or when the returned
     * normalized payload happens to be unchanged), the REPLACEMENT object -- not the original --
     * is what gets marked processed and dispatched on THIS same first delivery, so a strict
     * listener observes the enrichment immediately rather than only on a later retry.
     */
    public function processStored(string $uuid): void
    {
        $row = $this->events->findByUuid($uuid);
        if ($row === null || ($row['status'] ?? '') === 'processed') {
            if ($row !== null && ($row['dispatch_status'] ?? '') !== 'dispatched') {
                $this->dispatch($this->reconstruct($row), $uuid);
            }
            return;
        }

        $this->events->incrementAttempts($uuid);
        $this->events->markProcessing($uuid);
        $event = $this->reconstruct($row);

        try {
            $replacement = $this->applier !== null ? ($this->applier)($event) : null;

            if ($replacement !== null) {
                if ($replacement->normalized() !== $event->normalized()) {
                    if ($this->payloadUpdater === null) {
                        throw new \RuntimeException(sprintf(
                            'Provider event %s: applier returned a replacement with a changed normalized'
                                . ' payload, but no %s is bound to persist it.',
                            $uuid,
                            ProviderEventPayloadUpdaterInterface::class
                        ));
                    }

                    $this->payloadUpdater->replaceNormalizedPayload($uuid, $replacement->normalized());
                }

                $event = $replacement;
            }
        } catch (\Throwable $e) {
            $this->events->markFailed($uuid, $e->getMessage());
            throw $e;
        }

        $this->events->markProcessed($uuid);
        $this->dispatch($event, $uuid);
    }

    /**
     * Independent per row: a throwing dispatch (e.g. an unresolvable payment owner, or -- since
     * chargeback dispatch is strict -- a still-failing contracts listener) is logged and
     * skipped rather than allowed to abort the sweep. The failing row is simply left unclaimed/
     * unmarked (retryable on a later sweep); every other due row in this same call still gets
     * its chance to dispatch. Return/count semantics are unchanged: only rows that actually
     * dispatched on THIS call count towards the returned total.
     */
    public function relayPending(int $limit = 100, int $staleSeconds = 300): int
    {
        $count = 0;
        foreach ($this->events->findDispatchable($limit, $staleSeconds) as $row) {
            try {
                if ($this->dispatch($this->reconstruct($row), (string) $row['uuid'], $staleSeconds)) {
                    $count++;
                }
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[Payvia] relayPending(): dispatch failed for provider_events uuid=%s: %s',
                    (string) ($row['uuid'] ?? ''),
                    $e->getMessage()
                ));
            }
        }

        return $count;
    }

    private function dispatch(PaymentProviderEventInterface $event, string $uuid, int $staleSeconds = 300): bool
    {
        if ($event->type() === EventType::UNKNOWN) {
            $this->events->markDispatched($uuid);
            return false;
        }

        if ($this->events->isLogicalDispatched($event->gateway(), $event->logicalEventKey())) {
            $this->events->markDispatched($uuid);
            return false;
        }

        $leaseToken = null;
        if ($this->logicalDispatchLeases !== null) {
            $leaseToken = $this->logicalDispatchLeases->acquireLogicalDispatchLease(
                $event->gateway(),
                $event->logicalEventKey(),
                $staleSeconds
            );
            if ($leaseToken === null) {
                return false;
            }
        } else {
            $claimed = $this->events->claimLogicalForDispatch($event->gateway(), $event->logicalEventKey());
            if ($claimed === 0) {
                $claimed = $this->events->reclaimStaleDispatching(
                    $event->gateway(),
                    $event->logicalEventKey(),
                    $staleSeconds
                );
            }

            if ($claimed === 0) {
                return false;
            }
        }

        if ($this->dispatcher !== null) {
            try {
                ($this->dispatcher)(new PaymentProviderEvent($event));
                $this->finalizeOrigination($event);
            } catch (\Throwable $dispatchFailure) {
                if ($leaseToken !== null && $this->logicalDispatchLeases !== null) {
                    try {
                        $this->logicalDispatchLeases->releaseLogicalDispatch(
                            $event->gateway(),
                            $event->logicalEventKey(),
                            $leaseToken,
                        );
                    } catch (\Throwable $releaseFailure) {
                        error_log(sprintf(
                            '[Payvia] logical dispatch lease release failed for %s/%s: %s '
                            . '(stale-lease recovery remains the backstop)',
                            $event->gateway(),
                            $event->logicalEventKey(),
                            $releaseFailure->getMessage()
                        ));
                    }
                }
                throw $dispatchFailure;
            }
        }

        if ($leaseToken !== null && $this->logicalDispatchLeases !== null) {
            return $this->logicalDispatchLeases->completeLogicalDispatch(
                $event->gateway(),
                $event->logicalEventKey(),
                $leaseToken,
            );
        }

        $this->events->markLogicalDispatched($event->gateway(), $event->logicalEventKey());
        return true;
    }

    /**
     * The post-dispatch finalizer (design spec §3.6). Called from inside `dispatch()`'s own
     * try/catch, immediately after the composed `$dispatcher` (ordinary bus -> strict lane ->
     * chargeback) returns without throwing -- see the constructor docblock for exactly how a
     * failure here is handled identically to a dispatcher failure.
     *
     * Scoped to `subscription.created` ONLY: this is the activation-bearing event the whole
     * origination ledger exists to gate on. Correlation-only events (e.g. Paystack's preliminary
     * `charge.success` pre-pass, or ordinary `subscription.updated`/`subscription.canceled`
     * projection churn) may move a row to `provider_observed`, but per spec must NEVER finalize
     * it -- the origination awaits this one event.
     */
    private function finalizeOrigination(PaymentProviderEventInterface $event): void
    {
        if ($this->originations === null || $this->guards === null) {
            return;
        }
        if ($event->type() !== EventType::SUBSCRIPTION_CREATED) {
            return;
        }

        $origination = $this->resolveOriginationForFinalization($event->gateway(), $event->normalized());
        if ($origination === null) {
            return;
        }

        $status = (string) $origination['status'];
        if ($status === 'late_settlement_conflict') {
            $this->finalizeLateSettlementConflict($origination, $event->logicalEventKey());
            return;
        }

        if ($status !== 'provider_observed') {
            // Already finalized by an earlier attempt (dispatched / projection_rejected): a
            // redelivery landing here after a crash between finalize succeeding and the logical
            // dispatch lease being marked complete must be a silent, idempotent no-op so the
            // retry can still complete its own lease cleanly.
            return;
        }

        $requiredConsumer = $this->stringOrNull($origination['required_projection_consumer'] ?? null);
        if ($requiredConsumer === null) {
            // No required consumer: `dispatched` means only generic local dispatch completion.
            $this->completeOriginationDispatch($origination);
            return;
        }

        $logicalEventKey = $event->logicalEventKey();
        $outcome = $this->matchingAckOutcome($origination, $logicalEventKey);
        if ($outcome === null) {
            throw RequiredProjectionAcknowledgementMissing::forOrigination(
                (string) $origination['uuid'],
                $requiredConsumer,
                $logicalEventKey
            );
        }

        if ($outcome === 'accepted') {
            $this->completeOriginationDispatch($origination);
            return;
        }

        // Rejected: the required consumer durably rejected projection. The provider event still
        // finishes dispatching -- returning here (rather than throwing) is what lets `dispatch()`
        // go on to mark the logical dispatch complete -- but the origination stays live/
        // operator-visible (`projection_rejected`) instead of `dispatched`. The reason was
        // already durably recorded by the acknowledgement CAS write itself, before this finalizer
        // ever ran.
        $uuid = (string) $origination['uuid'];
        if (!$this->originations->transition($this->context, $uuid, 'provider_observed', 'projection_rejected')) {
            throw new \RuntimeException(sprintf(
                'Payvia: failed to advance checkout origination %s from provider_observed to '
                    . 'projection_rejected -- the row no longer matched the expected status '
                    . '(concurrent write?).',
                $uuid
            ));
        }
    }

    /**
     * `late_settlement_conflict` (design spec §3.3/§3.6) has NO further legal status transition
     * (`CheckoutOriginationRepository::TRANSITIONS['late_settlement_conflict']` is permanently
     * empty), so this never writes anything -- it only decides whether the CURRENT delivery may
     * complete (return) or must retry (throw).
     *
     * @param array<string,mixed> $origination
     */
    private function finalizeLateSettlementConflict(array $origination, string $logicalEventKey): void
    {
        $requiredConsumer = $this->stringOrNull($origination['required_projection_consumer'] ?? null);
        if ($requiredConsumer === null) {
            // No consumer was ever required for this origination: there is nothing to await an
            // acknowledgement for, and there is no further legal transition either way -- let the
            // signed provider event finish dispatching exactly once, per spec.
            return;
        }

        $outcome = $this->matchingAckOutcome($origination, $logicalEventKey);
        if ($outcome === 'rejected') {
            // The matching consumer durably rejected the mismatched reservation -- exactly the
            // deterministic outcome §3.3/§3.6 expect. Nothing to write: the conflict status and
            // the blocked guard both stay exactly as attemptLateSettlementConflict() left them.
            return;
        }

        throw RequiredProjectionAcknowledgementMissing::lateSettlementConflictUnresolved(
            (string) $origination['uuid'],
            $requiredConsumer,
            $logicalEventKey,
            $outcome,
        );
    }

    /**
     * Generic completion (design spec §3.6): `provider_observed -> dispatched` plus releasing the
     * live guard, shared by BOTH the "no required consumer" path and the "required consumer
     * accepted" path -- they are mechanically identical.
     *
     * ATOMIC (code review finding): the transition and the guard release run in ONE transaction
     * on the shared connection the constructor already asserts `$originations` and `$guards` use
     * -- mirroring `GatewaySubscriptionService::attemptLateSettlementConflict()`'s identical
     * idiom (Task 7). Without this, a committed `dispatched` transition followed by a REFUSED
     * guard release (e.g. a concurrent operator `block()` landing in between) would leave the
     * subject permanently stranded: a later retry's `finalizeOrigination()` sees `status !==
     * 'provider_observed'` and takes the idempotent-no-op early return, so the guard would never
     * be revisited again. Rolling BOTH writes back on a refused release instead leaves the
     * origination at `provider_observed`, so a retry genuinely re-drives both writes.
     *
     * @param array<string,mixed> $origination
     */
    private function completeOriginationDispatch(array $origination): void
    {
        /** @var CheckoutOriginationRepository $originations */
        $originations = $this->originations;
        /** @var CheckoutSubjectGuardRepository $guards */
        $guards = $this->guards;

        $uuid = (string) $origination['uuid'];
        $tenantUuid = (string) ($origination['tenant_uuid'] ?? '');
        $subjectKey = (string) ($origination['subject_key'] ?? '');

        $tx = $originations->getConnection()->getTransactionManager();
        $tx->begin();

        try {
            if (!$originations->transition($this->context, $uuid, 'provider_observed', 'dispatched')) {
                throw new \RuntimeException(sprintf(
                    'Payvia: failed to advance checkout origination %s from provider_observed to '
                        . 'dispatched -- the row no longer matched the expected status (concurrent write?).',
                    $uuid
                ));
            }

            $released = $guards->release($this->context, $tenantUuid, $subjectKey, $uuid);
            if (!$released) {
                throw new \RuntimeException(sprintf(
                    'Payvia: checkout origination %s could not advance to dispatched -- its subject '
                        . 'guard could not be released (bound to a different origination, or blocked?).',
                    $uuid
                ));
            }

            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollback();
            throw $e;
        }
    }

    /**
     * Resolve the correlated origination a `subscription.created` delivery finalizes against
     * (design spec §3.6): the enriched `origination_uuid` token GatewaySubscriptionService's
     * applier stamps into normalized metadata (the SAME top-level field that applier's own
     * `resolveOrigination()` reads), or -- absent that token -- the exact `(gateway,
     * provider_subscription_id)` pair the applier persisted on correlation. An origination
     * naming a DIFFERENT gateway than the delivering event is treated as no match at all, never
     * cross-gateway resolved.
     *
     * @param array<string,mixed> $normalized
     * @return array<string,mixed>|null
     */
    private function resolveOriginationForFinalization(string $gateway, array $normalized): ?array
    {
        /** @var CheckoutOriginationRepository $originations */
        $originations = $this->originations;

        $originationUuid = $this->stringOrNull($normalized['origination_uuid'] ?? null);
        if ($originationUuid !== null) {
            $origination = $originations->findByUuid($originationUuid);
            return $origination !== null && (string) $origination['gateway'] === $gateway ? $origination : null;
        }

        $providerSubscriptionId = $this->stringOrNull($normalized['gateway_subscription_id'] ?? null);
        if ($providerSubscriptionId === null) {
            return null;
        }

        return $originations->findByProviderSubscriptionId($gateway, $providerSubscriptionId);
    }

    /**
     * A durable acknowledgement (design spec §3.6) is "for THIS delivery" only when the row's
     * `projection_event_key` exactly matches the CURRENT event's logical key -- a stale
     * acknowledgement left over from a PRIOR occupant of this origination row (e.g. an earlier
     * `late_settlement_conflict` cycle, or a since-superseded correlation) must never be mistaken
     * for an answer to this one. Returns `null` when there is no matching acknowledgement yet.
     *
     * @param array<string,mixed> $origination
     */
    private function matchingAckOutcome(array $origination, string $logicalEventKey): ?string
    {
        $ackKey = $this->stringOrNull($origination['projection_event_key'] ?? null);
        if ($ackKey === null || $ackKey !== $logicalEventKey) {
            return null;
        }

        $outcome = $this->stringOrNull($origination['projection_outcome'] ?? null);
        return $outcome === 'accepted' || $outcome === 'rejected' ? $outcome : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function recordEvent(
        PaymentProviderEventInterface $event,
        string $source,
        bool $signatureValid,
    ): ?string {
        return $this->events->insertReceived([
            'gateway' => $event->gateway(),
            'source' => $source,
            'provider_event_id' => $event->providerEventId(),
            'delivery_key' => $event->deliveryKey(),
            'logical_event_key' => $event->logicalEventKey(),
            'type' => $event->type(),
            'signature_valid' => $signatureValid,
            'normalized_payload' => $event->normalized(),
            'raw_payload' => config($this->context, 'payvia.features.store_raw_payload', true)
                ? $event->raw()
                : null,
        ]);
    }

    /** @param array<string,mixed> $row */
    private function reconstruct(array $row): PaymentProviderEventInterface
    {
        $normalized = $this->decodeJson($row['normalized_payload'] ?? null);
        $raw = $this->decodeJson($row['raw_payload'] ?? null);

        return ProviderEvent::fromStored(
            gateway: (string) $row['gateway'],
            type: (string) $row['type'],
            providerEventId: isset($row['provider_event_id']) ? (string) $row['provider_event_id'] : null,
            deliveryKey: (string) $row['delivery_key'],
            logicalEventKey: (string) $row['logical_event_key'],
            occurredAt: new \DateTimeImmutable((string) ($row['received_at'] ?? 'now')),
            normalized: $normalized,
            raw: $raw,
        );
    }

    /** @return array<string,mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
