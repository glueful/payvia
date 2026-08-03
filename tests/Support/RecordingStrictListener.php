<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Support;

use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
use Glueful\Extensions\Payvia\Contracts\StrictPaymentEventListener;

/**
 * A strict listener the REAL container can construct on its own (no constructor
 * arguments), recording into process-static state so a test can observe it after
 * the container built and invoked it.
 *
 * {@see FakeStrictPaymentEventListener} is the instance-state equivalent used
 * wherever the test hand-builds the lane; this one exists specifically because
 * `PayviaServiceProvider::makeWebhookService()` composes the lane from whatever
 * the container resolves under the tag -- the test never gets to hand it an
 * instance, so the recorder has to be reachable by class.
 */
final class RecordingStrictListener implements StrictPaymentEventListener
{
    /**
     * The shared lane-order log. All three lanes push their marker here so a
     * single array pins the relative order of ordinary/strict/chargebacks.
     *
     * @var list<string>
     */
    public static array $order = [];

    /** Set to make handle() throw -- the release-the-lease case. */
    public static ?\Throwable $throwOnHandle = null;

    public static function reset(): void
    {
        self::$order = [];
        self::$throwOnHandle = null;
    }

    public function supports(PaymentProviderEventInterface $event): bool
    {
        return true;
    }

    public function handle(PaymentProviderEventInterface $event): void
    {
        self::$order[] = 'strict';

        if (self::$throwOnHandle !== null) {
            throw self::$throwOnHandle;
        }
    }
}
