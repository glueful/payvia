<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Extensions\Payvia\Database\Migrations\CreatePaymentIntentsTable;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

final class PaymentIntentRepositoryTest extends PayviaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->runMigration(new CreatePaymentIntentsTable());
    }

    public function testOpenIntentIsUniquePerPayable(): void
    {
        $repo = new PaymentIntentRepository($this->connection);

        self::assertTrue($repo->createOpen($this->context, $this->intentRow('commerce_order', 'ord1', 'ref-a')));
        self::assertFalse($repo->createOpen($this->context, $this->intentRow('commerce_order', 'ord1', 'ref-b')));

        $open = $repo->findOpen($this->context, 'commerce_order', 'ord1');
        self::assertIsArray($open);
        self::assertSame('ref-a', $open['reference']);
    }

    public function testClosingReleasesTheKeyForANewIntent(): void
    {
        $repo = new PaymentIntentRepository($this->connection);

        self::assertTrue($repo->createOpen($this->context, $this->intentRow('commerce_order', 'ord2', 'ref-a')));
        $open = $repo->findOpen($this->context, 'commerce_order', 'ord2');
        self::assertIsArray($open);

        $repo->close($this->context, (string) $open['uuid'], 'ref-a');

        self::assertNull($repo->findOpen($this->context, 'commerce_order', 'ord2'));
        self::assertTrue($repo->createOpen($this->context, $this->intentRow('commerce_order', 'ord2', 'ref-c')));
    }

    /** @return array<string,mixed> */
    private function intentRow(string $type, string $id, string $reference): array
    {
        return [
            'payable_type' => $type,
            'payable_id' => $id,
            'gateway' => 'paystack',
            'reference' => $reference,
            'amount' => 4999,
            'currency' => 'GHS',
            'payload' => ['checkout_url' => 'https://checkout.test/' . $reference],
        ];
    }
}
