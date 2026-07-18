<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Services;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
use Glueful\Extensions\Payvia\Contracts\ProviderEventRepositoryInterface;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Events\PaymentProviderEvent;
use Glueful\Extensions\Payvia\Events\ProviderEvent;
use Glueful\Extensions\Payvia\GatewayManager;

final class WebhookService
{
    /**
     * WebhookService remains the SOLE durable `provider_events` owner: `$dispatcher` is called
     * from `dispatch()` only after a delivery has been persisted and atomically claimed for its
     * logical key, and only `markLogicalDispatched()` (run after `$dispatcher` returns without
     * throwing) can ever mark a logical dispatch done. `PayviaServiceProvider::makeWebhookService()`
     * composes `$dispatcher` from two steps run in order: ordinary local `PaymentProviderEvent`
     * delivery first, then delegation to `Events\ProviderChargebackDispatcher` for recognized
     * dispute/chargeback types. Neither step is caught here -- any exception from either half
     * propagates out of `dispatch()` and leaves the logical dispatch unmarked, so the row stays
     * redispatchable via `relayPending()`.
     *
     * @param null|callable(PaymentProviderEvent):void $dispatcher
     * @param null|callable(PaymentProviderEventInterface):void $applier
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
    ) {
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
            if ($this->applier !== null) {
                ($this->applier)($event);
            }
            $this->events->markProcessed($uuid);
            $this->dispatch($event, $uuid);
        } catch (\Throwable $e) {
            $this->events->markFailed($uuid, $e->getMessage());
            throw $e;
        }
    }

    public function relayPending(int $limit = 100, int $staleSeconds = 300): int
    {
        $count = 0;
        foreach ($this->events->findDispatchable($limit, $staleSeconds) as $row) {
            if ($this->dispatch($this->reconstruct($row), (string) $row['uuid'], $staleSeconds)) {
                $count++;
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

        if ($this->dispatcher !== null) {
            ($this->dispatcher)(new PaymentProviderEvent($event));
        }

        $this->events->markLogicalDispatched($event->gateway(), $event->logicalEventKey());
        return true;
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
