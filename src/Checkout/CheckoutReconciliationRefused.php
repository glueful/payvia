<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Checkout;

/**
 * Thrown by {@see CheckoutReconciliationService::resolve()} (design spec §3.8) for every genuine
 * refusal: an unknown resolution (no bare `ignore`), an empty audit note, an unknown origination,
 * a starting status the service does not reconcile at all, `provider_confirmed_dead` attempted
 * once the ledger has ever observed provider money/subscription state,
 * `provider_canceled_or_refunded` attempted against a row that never observed any, or a subject
 * guard that no longer binds the exact origination being resolved (it moved out from under the
 * caller -- {@see \Glueful\Extensions\Payvia\Repositories\CheckoutSubjectGuardRepository::block()}
 * is unconditional, so a second block with a different origination can overwrite the binding this
 * call expected; that is surfaced here as a refusal, never a crash).
 */
final class CheckoutReconciliationRefused extends \RuntimeException
{
    /**
     * Stable, greppable marker prefixed onto every message so diagnostics/log tooling can
     * classify this failure type precisely.
     */
    public const MARKER = 'checkout_reconciliation_refused';

    public static function unknownResolution(string $resolution): self
    {
        return new self(sprintf(
            '%s: "%s" is not a recognized resolution; a bare "ignore" is never accepted.',
            self::MARKER,
            $resolution
        ));
    }

    public static function emptyAuditNote(string $originationUuid): self
    {
        return new self(sprintf(
            '%s: checkout origination %s requires a non-empty audit note to resolve.',
            self::MARKER,
            $originationUuid
        ));
    }

    public static function unknownOrigination(string $originationUuid): self
    {
        return new self(sprintf(
            '%s: no checkout origination found for uuid "%s".',
            self::MARKER,
            $originationUuid
        ));
    }

    public static function notReconcilableStatus(string $originationUuid, string $status): self
    {
        return new self(sprintf(
            '%s: checkout origination %s is in status "%s", which operator reconciliation does not resolve.',
            self::MARKER,
            $originationUuid,
            $status
        ));
    }

    public static function moneyAlreadyObserved(string $originationUuid): self
    {
        return new self(sprintf(
            '%s: checkout origination %s already observed provider money/subscription state; '
                . '"provider_confirmed_dead" is refused. Use "provider_canceled_or_refunded" instead.',
            self::MARKER,
            $originationUuid
        ));
    }

    public static function moneyNeverObserved(string $originationUuid): self
    {
        return new self(sprintf(
            '%s: checkout origination %s never observed provider money/subscription state; '
                . '"provider_canceled_or_refunded" is refused. Use "provider_confirmed_dead" instead.',
            self::MARKER,
            $originationUuid
        ));
    }

    public static function transitionRaced(string $originationUuid): self
    {
        return new self(sprintf(
            '%s: checkout origination %s moved under a concurrent write; resolution refused rather '
                . 'than applied against stale state.',
            self::MARKER,
            $originationUuid
        ));
    }

    public static function guardBindingMoved(string $originationUuid, string $subjectKey): self
    {
        return new self(sprintf(
            '%s: the subject guard for "%s" no longer binds checkout origination %s; it moved '
                . 'before this reconciliation could reopen it.',
            self::MARKER,
            $subjectKey,
            $originationUuid
        ));
    }
}
