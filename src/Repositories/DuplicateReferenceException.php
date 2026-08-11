<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Repositories;

/**
 * Thrown by {@see PaymentIntentRepository::createOpen()} and
 * {@see PaymentIntentRepository::markOpen()} when a write collides with the portable
 * `UNIQUE(tenant_uuid, gateway, reference)` constraint (payment-links Task 1 migration) against a
 * DIFFERENT row than the one the caller is trying to reach.
 *
 * This is a REACHABLE production state, not a theoretical one: a gateway with a fixed,
 * time-boxed idempotency key (Stripe replays the same checkout session id/reference for ~24h for
 * an identical request) can hand back the IDENTICAL reference for a retried attempt on a payable
 * whose earlier attempt already retired (closed/superseded/failed) under that exact reference.
 * `createOpen()`/`markOpen()` cannot silently treat this the same as "the active port is already
 * taken" (an existing `open` row for THIS payable, recoverable via {@see
 * PaymentIntentRepository::findOpen()}) -- the colliding row here belongs to a DIFFERENT,
 * already-terminal attempt, so there is nothing live to recover, and swallowing the write while
 * still reporting success would silently lose the payment record. Callers must surface this as a
 * genuine initiation/collection failure rather than fabricate an unpersisted "ok".
 */
final class DuplicateReferenceException extends \RuntimeException
{
    public function __construct(
        public readonly string $payableType,
        public readonly string $payableId,
        public readonly string $gateway,
        public readonly ?string $reference,
    ) {
        parent::__construct(sprintf(
            'payment_intents: gateway "%s" reference "%s" is already in use by another '
                . 'attempt (payable %s:%s).',
            $gateway,
            $reference ?? '(null)',
            $payableType,
            $payableId
        ));
    }
}
