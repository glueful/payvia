<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Extensions\Payvia\Contracts\WebhookCapableGateway;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Tests\Support\FakeWebhookGateway;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

final class GatewayManagerCapabilityTest extends PayviaTestCase
{
    private function manager(): GatewayManager
    {
        $fake = new FakeWebhookGateway();
        $this->bind(FakeWebhookGateway::class, $fake);
        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver('fake', FakeWebhookGateway::class);

        return $manager;
    }

    public function testSupportsAndTypedResolvers(): void
    {
        $manager = $this->manager();

        self::assertTrue($manager->supports('fake', 'webhook'));
        self::assertTrue($manager->supports('fake', 'subscription'));
        self::assertInstanceOf(WebhookCapableGateway::class, $manager->webhookGateway('fake'));
    }

    public function testUnsupportedCapabilityThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->manager()->webhookGateway('does-not-exist');
    }
}
