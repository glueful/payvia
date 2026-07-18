<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Extensions\Payvia\Contracts\TransferCapableGateway;
use Glueful\Extensions\Payvia\Contracts\WebhookCapableGateway;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Tests\Support\FakeTransferGateway;
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

        $transferFake = new FakeTransferGateway();
        $this->bind(FakeTransferGateway::class, $transferFake);
        $manager->registerDriver('transfer-fake', FakeTransferGateway::class);

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

    public function testPayoutCapableGatewaySupportsPayout(): void
    {
        $manager = $this->manager();

        self::assertTrue($manager->supports('transfer-fake', 'payout'));
        self::assertInstanceOf(TransferCapableGateway::class, $manager->payoutGateway('transfer-fake'));
    }

    public function testNonPayoutCapableGatewayDoesNotSupportPayout(): void
    {
        self::assertFalse($this->manager()->supports('fake', 'payout'));
    }

    public function testPayoutGatewayThrowsForNonCapableGateway(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->manager()->payoutGateway('fake');
    }

    public function testPayoutGatewayThrowsForUnknownGateway(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->manager()->payoutGateway('does-not-exist');
    }
}
