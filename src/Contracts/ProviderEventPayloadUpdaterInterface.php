<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Contracts;

/**
 * Persists an applier-produced replacement of a `provider_events` row's normalized payload.
 *
 * Deliberately NOT folded into {@see ProviderEventRepositoryInterface}: that interface is a
 * public substitution seam, and widening it with a new abstract method would break existing
 * implementations in a 2.5 minor release. This is an additive capability a repository MAY also
 * implement, resolved the same way {@see LogicalDispatchLeaseRepositoryInterface} is -- via an
 * `instanceof` check against the SAME `ProviderEventRepositoryInterface` instance, never a
 * separate container binding.
 *
 * `WebhookService::processStored()` calls this ONLY when its applier callable returns a non-null
 * replacement `PaymentProviderEventInterface` whose `normalized()` actually differs from the
 * event that was applied, and ONLY before `markProcessed()` -- so a crash between the write and
 * the mark can never leave a `processed` row pointing at stale metadata. A repository that does
 * not implement this interface makes that same situation fail closed instead: the applier's
 * enrichment is discarded, the row is marked failed, and the exception rethrows, exactly as if
 * the applier itself had thrown.
 */
interface ProviderEventPayloadUpdaterInterface
{
    /** @param array<string,mixed> $normalized */
    public function replaceNormalizedPayload(string $uuid, array $normalized): void;
}
