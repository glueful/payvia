<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface;
use Glueful\Extensions\Payvia\Contracts\PaymentRepositoryInterface;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Services\PaymentService;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

/**
 * Concurrent webhook/client retries are a normal occurrence with payment
 * providers. A find-then-insert on payments.reference (UNIQUE) can lose the
 * race; the service must recover via the update path instead of 500ing.
 */
final class PaymentConfirmUniqueRaceTest extends PayviaTestCase
{
    private function gateway(): PaymentGatewayInterface
    {
        return new class implements PaymentGatewayInterface {
            /**
             * @param array<string,mixed> $options
             * @return array<string,mixed>
             */
            public function verify(string $reference, array $options = []): array
            {
                return [
                    'status' => 'success',
                    'id' => 'gw_tx_1',
                    'message' => 'ok',
                    'amount' => 100.0,
                    'currency' => 'GHS',
                ];
            }
        };
    }

    private function service(PaymentRepositoryInterface $repo): PaymentService
    {
        $gateway = $this->gateway();
        $this->bind($gateway::class, $gateway);
        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver('paystack', $gateway::class);

        return new PaymentService($this->context, $repo, $manager);
    }

    public function testRecoversFromUniqueViolationByUpdating(): void
    {
        // findByReference returns null (stale read), but a concurrent writer
        // inserted the row, so createPayment hits a UNIQUE constraint.
        $repo = new class implements PaymentRepositoryInterface {
            /** @var array<string,mixed>|null */
            public ?array $updatedWith = null;
            public ?string $updatedReference = null;

            public function getTableName(): string
            {
                return 'payments';
            }

            /** @param array<string,mixed> $data */
            public function createPayment(array $data): string
            {
                throw new \RuntimeException('SQLSTATE[23000]: UNIQUE constraint failed: payments.reference');
            }

            /** @return array<string,mixed>|null */
            public function findByReference(string $reference): ?array
            {
                return null;
            }

            /** @param array<string,mixed> $data */
            public function updateByReference(string $reference, array $data): bool
            {
                $this->updatedReference = $reference;
                $this->updatedWith = $data;
                return true;
            }
        };

        $result = $this->service($repo)->confirmAndRecord('ref_race', 'paystack');

        self::assertSame('success', $result['payment_status']);
        self::assertSame('ref_race', $repo->updatedReference);
        self::assertNotNull($repo->updatedWith);
        // The update path applies the same payload the insert would have.
        self::assertSame('ref_race', $repo->updatedWith['reference']);
        self::assertSame(100.0, $repo->updatedWith['amount']);
        self::assertSame('success', $repo->updatedWith['status']);
    }

    public function testNonUniqueExceptionPropagates(): void
    {
        $repo = new class implements PaymentRepositoryInterface {
            public bool $updateCalled = false;

            public function getTableName(): string
            {
                return 'payments';
            }

            /** @param array<string,mixed> $data */
            public function createPayment(array $data): string
            {
                throw new \RuntimeException('SQLSTATE[HY000]: disk I/O error');
            }

            /** @return array<string,mixed>|null */
            public function findByReference(string $reference): ?array
            {
                return null;
            }

            /** @param array<string,mixed> $data */
            public function updateByReference(string $reference, array $data): bool
            {
                $this->updateCalled = true;
                return true;
            }
        };

        try {
            $this->service($repo)->confirmAndRecord('ref_fail', 'paystack');
            self::fail('Expected the non-unique exception to propagate.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('disk I/O error', $e->getMessage());
        }

        self::assertFalse($repo->updateCalled, 'Update must not run for non-unique failures.');
    }
}
