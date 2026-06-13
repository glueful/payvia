<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Events;

final class EventType
{
    public const PAYMENT_SUCCEEDED = 'payment.succeeded';
    public const PAYMENT_FAILED = 'payment.failed';
    public const INVOICE_PAID = 'invoice.paid';
    public const INVOICE_PAYMENT_FAILED = 'invoice.payment_failed';
    public const SUBSCRIPTION_CREATED = 'subscription.created';
    public const SUBSCRIPTION_UPDATED = 'subscription.updated';
    public const SUBSCRIPTION_PAST_DUE = 'subscription.past_due';
    public const SUBSCRIPTION_CANCELED = 'subscription.canceled';
    public const UNKNOWN = 'unknown';

    /** @var list<string> */
    private const IMMUTABLE = [
        self::PAYMENT_SUCCEEDED,
        self::INVOICE_PAID,
        self::INVOICE_PAYMENT_FAILED,
        self::SUBSCRIPTION_CREATED,
        self::SUBSCRIPTION_CANCELED,
    ];

    /** @var list<string> */
    private const MUTABLE = [
        // payment.failed is mutable so repeat failures for the same entity
        // (e.g. a retried-and-failed Stripe payment_intent) get distinct
        // logical keys instead of being deduplicated away — the app must hear
        // about each failure.
        self::PAYMENT_FAILED,
        self::SUBSCRIPTION_UPDATED,
        self::SUBSCRIPTION_PAST_DUE,
    ];

    public static function isImmutable(string $type): bool
    {
        return in_array($type, self::IMMUTABLE, true);
    }

    public static function isKnown(string $type): bool
    {
        return in_array($type, self::IMMUTABLE, true)
            || in_array($type, self::MUTABLE, true);
    }
}
