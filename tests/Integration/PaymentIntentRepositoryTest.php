<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Extensions\Payvia\Database\Migrations\AddPaymentIntentAttemptLifecycle;
use Glueful\Extensions\Payvia\Database\Migrations\CreatePaymentIntentsTable;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

final class PaymentIntentRepositoryTest extends PayviaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->runMigration(new CreatePaymentIntentsTable());
        $this->runMigration(new AddPaymentIntentAttemptLifecycle());
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

    // ==================================================================
    // claimAttempt() / markOpen() / supersede() / close() / fail() --
    // payment-links Task 1's attempt lifecycle.
    // ==================================================================

    public function testClaimAttemptInsertsInitializingRowWithNullReferenceAndTheActivePortKey(): void
    {
        $repo = new PaymentIntentRepository($this->connection);

        $claimed = $repo->claimAttempt($this->context, $this->attemptRow('commerce_order', 'ord10'));

        self::assertNotSame('', $claimed['uuid']);
        self::assertSame('initializing', $claimed['status']);
        self::assertNull($claimed['reference']);
        self::assertSame('commerce_order:ord10', $claimed['idempotency_key']);
    }

    public function testFindOpenDoesNotSeeAnInitializingAttempt(): void
    {
        $repo = new PaymentIntentRepository($this->connection);
        $repo->claimAttempt($this->context, $this->attemptRow('commerce_order', 'ord11'));

        // Binding contract: findOpen() must keep working for CURRENT callers exactly as before
        // -- it only ever sees `open` rows, never `initializing` ones.
        self::assertNull($repo->findOpen($this->context, 'commerce_order', 'ord11'));
    }

    public function testClaimAttemptRetryAfterATransportTimeoutReusesTheSameAttemptUuidAndKey(): void
    {
        $repo = new PaymentIntentRepository($this->connection);

        $first = $repo->claimAttempt($this->context, $this->attemptRow('commerce_order', 'ord12'));
        // Simulates a transport timeout/crash before this attempt ever resolved: the caller
        // retries the identical claim for the same payable.
        $second = $repo->claimAttempt($this->context, $this->attemptRow('commerce_order', 'ord12'));

        self::assertSame($first['uuid'], $second['uuid']);
        self::assertSame($first['idempotency_key'], $second['idempotency_key']);
        self::assertSame(1, $this->connection->table('payment_intents')->count());
    }

    public function testClaimAttemptForDifferentPayablesGetsIndependentAttempts(): void
    {
        $repo = new PaymentIntentRepository($this->connection);

        $a = $repo->claimAttempt($this->context, $this->attemptRow('commerce_order', 'ord13'));
        $b = $repo->claimAttempt($this->context, $this->attemptRow('commerce_order', 'ord14'));

        self::assertNotSame($a['uuid'], $b['uuid']);
        self::assertSame(2, $this->connection->table('payment_intents')->count());
    }

    public function testMarkOpenTransitionsInitializingToOpenAndFindOpenSeesIt(): void
    {
        $repo = new PaymentIntentRepository($this->connection);
        $claimed = $repo->claimAttempt($this->context, $this->attemptRow('commerce_order', 'ord15'));

        self::assertTrue(
            $repo->markOpen($this->context, (string) $claimed['uuid'], 'ref-open', ['checkout_url' => 'https://x'])
        );

        $open = $repo->findOpen($this->context, 'commerce_order', 'ord15');
        self::assertIsArray($open);
        self::assertSame('ref-open', $open['reference']);
        self::assertSame($claimed['uuid'], $open['uuid']);
        self::assertSame(['checkout_url' => 'https://x'], $open['payload']);
    }

    public function testMarkOpenIsANoOpWhenTheRowIsNotInitializing(): void
    {
        $repo = new PaymentIntentRepository($this->connection);
        $claimed = $repo->claimAttempt($this->context, $this->attemptRow('commerce_order', 'ord16'));
        self::assertTrue($repo->markOpen($this->context, (string) $claimed['uuid'], 'ref-a'));

        // Already open -- a second markOpen (e.g. a redelivered/duplicated call) must not
        // clobber the reference.
        self::assertFalse($repo->markOpen($this->context, (string) $claimed['uuid'], 'ref-b'));

        $open = $repo->findOpen($this->context, 'commerce_order', 'ord16');
        self::assertSame('ref-a', $open['reference']);
    }

    public function testFailFreesTheActivePortForAFreshClaimAttempt(): void
    {
        $repo = new PaymentIntentRepository($this->connection);
        $claimed = $repo->claimAttempt($this->context, $this->attemptRow('commerce_order', 'ord17'));

        // A classified deterministic provider rejection.
        self::assertTrue($repo->fail($this->context, (string) $claimed['uuid']));

        $retry = $repo->claimAttempt($this->context, $this->attemptRow('commerce_order', 'ord17'));
        self::assertNotSame($claimed['uuid'], $retry['uuid'], 'failing must free the port for a NEW attempt');
        self::assertSame(2, $this->connection->table('payment_intents')->count());
    }

    public function testFailIsIllegalOnceOpen(): void
    {
        $repo = new PaymentIntentRepository($this->connection);
        $claimed = $repo->claimAttempt($this->context, $this->attemptRow('commerce_order', 'ord18'));
        $repo->markOpen($this->context, (string) $claimed['uuid'], 'ref-a');

        // A row that reached `open` collected successfully -- it is closed, never failed.
        self::assertFalse($repo->fail($this->context, (string) $claimed['uuid']));
        self::assertIsArray($repo->findOpen($this->context, 'commerce_order', 'ord18'));
    }

    public function testSupersedeFreesTheActivePortForAFreshClaimAttempt(): void
    {
        $repo = new PaymentIntentRepository($this->connection);
        $claimed = $repo->claimAttempt($this->context, $this->attemptRow('commerce_order', 'ord19'));

        self::assertTrue($repo->supersede($this->context, (string) $claimed['uuid']));

        $retry = $repo->claimAttempt($this->context, $this->attemptRow('commerce_order', 'ord19'));
        self::assertNotSame($claimed['uuid'], $retry['uuid']);
    }

    public function testCloseFreesTheActivePortForAFreshClaimAttempt(): void
    {
        $repo = new PaymentIntentRepository($this->connection);
        $claimed = $repo->claimAttempt($this->context, $this->attemptRow('commerce_order', 'ord20'));
        $repo->markOpen($this->context, (string) $claimed['uuid'], 'ref-a');

        $repo->close($this->context, (string) $claimed['uuid'], 'ref-a');

        self::assertNull($repo->findOpen($this->context, 'commerce_order', 'ord20'));
        $retry = $repo->claimAttempt($this->context, $this->attemptRow('commerce_order', 'ord20'));
        self::assertNotSame($claimed['uuid'], $retry['uuid']);
    }

    /** @return array<string,mixed> */
    private function attemptRow(string $type, string $id): array
    {
        return [
            'payable_type' => $type,
            'payable_id' => $id,
            'gateway' => 'paystack',
            'amount' => 4999,
            'currency' => 'GHS',
        ];
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
