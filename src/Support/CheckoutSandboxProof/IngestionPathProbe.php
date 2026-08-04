<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Support\CheckoutSandboxProof;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\Contracts\ProviderEventRepositoryInterface;
use Glueful\Queue\QueueManager;

/**
 * Gathers the ONE fact {@see SandboxProofPreflight} cannot gather itself: whether the app/worker
 * ingestion path for `provider_events` is actually running right now.
 *
 * Documented probe (deliberately two-part, matching the task brief's "queue config sanity or a
 * recent-ingestion check"):
 *
 *  1. Queue config sanity — if `payvia.webhooks.queue` is enabled, a bound {@see QueueManager}
 *     must exist. Without one, webhook rows would be accepted and stored but never handed to a
 *     worker, so a "processed" a fixture-worthy row would never actually be dispatched.
 *  2. Reachability, not recency — a lightweight read against `provider_events` via the bound
 *     {@see ProviderEventRepositoryInterface} (`findDispatchable(1, 0)`) must not throw. This is
 *     deliberately NOT a "has a row landed recently" check: a fresh install with zero webhook
 *     traffic yet must still be able to run this very command to generate its first one. A
 *     throw here (missing table, unreachable database, etc.) means the ingestion path the
 *     webhook controller writes into cannot be trusted to receive anything either.
 */
final class IngestionPathProbe
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    /**
     * @return array{0: bool, 1: string} [reachable, human-readable detail]
     */
    public function probe(): array
    {
        if (!$this->context->hasContainer()) {
            return [false, 'Application container is not available.'];
        }

        $container = $this->context->getContainer();

        if (!$container->has(ProviderEventRepositoryInterface::class)) {
            return [false, 'ProviderEventRepositoryInterface is not bound in the container.'];
        }

        $queueEnabled = (bool) ($this->context->getConfig('payvia.webhooks.queue', false) ?? false);
        if ($queueEnabled && !$container->has(QueueManager::class)) {
            return [false, 'payvia.webhooks.queue is enabled but no QueueManager is bound (webhook rows '
                . 'would be accepted and stored but never dispatched to a worker).'];
        }

        $events = $container->get(ProviderEventRepositoryInterface::class);
        if (!$events instanceof ProviderEventRepositoryInterface) {
            return [false, 'Bound ProviderEventRepositoryInterface does not implement the contract.'];
        }

        try {
            $events->findDispatchable(1, 0);
        } catch (\Throwable $e) {
            return [false, 'provider_events is not reachable: ' . $e->getMessage()];
        }

        return [true, $queueEnabled
            ? 'provider_events reachable; QueueManager bound for async dispatch.'
            : 'provider_events reachable; synchronous dispatch mode (payvia.webhooks.queue disabled).'];
    }
}
