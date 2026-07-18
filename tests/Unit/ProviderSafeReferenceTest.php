<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Unit;

use Glueful\Extensions\Payvia\Support\ProviderSafeReference;
use PHPUnit\Framework\TestCase;

final class ProviderSafeReferenceTest extends TestCase
{
    public function testStripeReferenceIsTheCanonicalKeyUnchanged(): void
    {
        $canonical = 'pay_abc123:attempt:1';

        self::assertSame($canonical, ProviderSafeReference::forStripe($canonical));
    }

    public function testPaystackReferenceObeysCharsetAndLength(): void
    {
        $canonical = 'pay_abc123:attempt:1';

        $reference = ProviderSafeReference::forPaystack($canonical);

        self::assertMatchesRegularExpression('/^[a-z0-9_-]+$/', $reference);
        self::assertGreaterThanOrEqual(16, strlen($reference));
        self::assertLessThanOrEqual(50, strlen($reference));
        self::assertStringNotContainsString(':', $reference);
    }

    public function testPaystackReferenceIsStableForTheSameCanonicalKey(): void
    {
        $canonical = 'pay_abc123:attempt:1';

        self::assertSame(
            ProviderSafeReference::forPaystack($canonical),
            ProviderSafeReference::forPaystack($canonical)
        );
    }

    public function testPaystackReferenceDiffersForDifferentAttempts(): void
    {
        $attempt1 = ProviderSafeReference::forPaystack('pay_abc123:attempt:1');
        $attempt2 = ProviderSafeReference::forPaystack('pay_abc123:attempt:2');

        self::assertNotSame($attempt1, $attempt2);
    }

    public function testPaystackReferenceNeverEqualsTheRawCanonicalKey(): void
    {
        $canonical = 'pay_abc123:attempt:1';

        self::assertNotSame($canonical, ProviderSafeReference::forPaystack($canonical));
    }
}
