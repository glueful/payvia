<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Unit;

use Glueful\Extensions\Payvia\PayviaServiceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * `PayviaServiceProvider::getVersion()` (and its `composerVersion()` backer) must reflect the
 * extension manifest's `extra.glueful.version` field -- the field Composer/the extension
 * installer actually reads for `glueful/payvia` -- not a top-level `version` key (Composer
 * discourages that field entirely and this package doesn't declare one).
 */
final class PayviaServiceProviderTest extends TestCase
{
    public function testGetVersionReadsExtraGluefulVersionFromComposerJson(): void
    {
        $provider = new PayviaServiceProvider($this->createStub(ContainerInterface::class));

        self::assertSame('2.0.0', $provider->getVersion());
    }
}
