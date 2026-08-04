<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Unit;

use Glueful\Extensions\Payvia\PayviaServiceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * `PayviaServiceProvider::getVersion()` (and its `composerVersion()` backer) must reflect the
 * extension manifest's `extra.glueful.version` field -- the field Composer/the extension
 * installer actually reads for `glueful/payvia` -- not the top-level `version` key. The root
 * `version` field (Composer discourages it in general) is declared solely so local `path`-type
 * repositories -- e.g. `glueful/subscriptions`'s dev-only sibling checkout, before 2.5.0 is
 * published to Packagist -- resolve this checkout at the correct version instead of guessing
 * a branch alias; it must stay in lockstep with `extra.glueful.version` but is not what this
 * getter reads.
 */
final class PayviaServiceProviderTest extends TestCase
{
    public function testGetVersionReadsExtraGluefulVersionFromComposerJson(): void
    {
        $provider = new PayviaServiceProvider($this->createStub(ContainerInterface::class));

        self::assertSame('2.5.0', $provider->getVersion());
    }
}
