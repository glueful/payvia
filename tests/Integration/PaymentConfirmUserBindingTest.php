<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Auth\AuthenticationManager;
use Glueful\Auth\UserIdentity;
use Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface;
use Glueful\Extensions\Payvia\Contracts\PaymentRepositoryInterface;
use Glueful\Extensions\Payvia\Controllers\PaymentController;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Services\PaymentService;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Security: the stored payments.user_uuid must come from the authenticated session,
 * never from the request body.
 */
final class PaymentConfirmUserBindingTest extends PayviaTestCase
{
    /** @var array<int,array<string,mixed>> Captured rows written by the fake repository. */
    private array $written = [];

    private PaymentController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $written = &$this->written;

        // Fake repository captures the payload that would be persisted.
        $repo = new class ($written) implements PaymentRepositoryInterface {
            /** @param array<int,array<string,mixed>> $written */
            public function __construct(private array &$written)
            {
            }

            public function getTableName(): string
            {
                return 'payments';
            }

            /** @param array<string,mixed> $data */
            public function createPayment(array $data): string
            {
                $this->written[] = $data;
                return 'pay_1';
            }

            /** @return array<string,mixed>|null */
            public function findByReference(string $reference): ?array
            {
                return null;
            }

            /** @param array<string,mixed> $data */
            public function updateByReference(string $reference, array $data): bool
            {
                $this->written[] = $data;
                return true;
            }
        };

        // Fake gateway returns a fixed successful verification.
        $gateway = new class implements PaymentGatewayInterface {
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
                    'amount' => 10000,
                    'currency' => 'GHS',
                ];
            }
        };

        // GatewayManager resolves driver classes from the container; register the fake.
        $this->bind($gateway::class, $gateway);
        $container = $this->context->getContainer();
        $manager = new GatewayManager($container, $this->context);
        $manager->registerDriver('paystack', $gateway::class);

        $service = new PaymentService($this->context, $repo, $manager);

        $this->bind(AuthenticationManager::class, $this->createMock(AuthenticationManager::class));
        $this->bind(Request::class, new Request());

        $this->controller = new PaymentController($this->context, $service);
    }

    /** @param array<string,mixed> $body */
    private function jsonRequest(array $body): Request
    {
        return new Request([], [], [], [], [], [], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** Force the protected authenticated identity that BaseController would otherwise resolve. */
    private function authenticateAs(?string $uuid): void
    {
        $ref = new \ReflectionProperty($this->controller, 'currentUser');
        $ref->setAccessible(true);
        $ref->setValue($this->controller, $uuid === null ? null : new UserIdentity($uuid));
    }

    public function testUsesAuthenticatedUuidAndIgnoresAbsentBodyValue(): void
    {
        $this->authenticateAs('user_session_abc');

        $response = $this->controller->confirm($this->jsonRequest([
            'reference' => 'ref_1',
            'gateway' => 'paystack',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->written);
        self::assertSame('user_session_abc', $this->written[0]['user_uuid']);
    }

    public function testRejectsSpoofedUserUuidThatDiffersFromSession(): void
    {
        $this->authenticateAs('user_session_abc');

        $response = $this->controller->confirm($this->jsonRequest([
            'reference' => 'ref_2',
            'gateway' => 'paystack',
            'user_uuid' => 'victim_user_xyz',
        ]));

        // Must be rejected (422) and nothing persisted with the spoofed value.
        self::assertSame(422, $response->getStatusCode());
        self::assertCount(0, $this->written);
    }

    public function testAcceptsMatchingUserUuid(): void
    {
        $this->authenticateAs('user_session_abc');

        $response = $this->controller->confirm($this->jsonRequest([
            'reference' => 'ref_3',
            'gateway' => 'paystack',
            'user_uuid' => 'user_session_abc',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('user_session_abc', $this->written[0]['user_uuid']);
    }

    public function testFallsBackToNullWhenNoAuthenticatedUser(): void
    {
        $this->authenticateAs(null);

        $response = $this->controller->confirm($this->jsonRequest([
            'reference' => 'ref_4',
            'gateway' => 'paystack',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->written[0]['user_uuid']);
    }

    public function testRejectsBodyUuidWhenNoAuthenticatedUser(): void
    {
        $this->authenticateAs(null);

        $response = $this->controller->confirm($this->jsonRequest([
            'reference' => 'ref_5',
            'gateway' => 'paystack',
            'user_uuid' => 'anything',
        ]));

        // Defensive: a body value cannot be trusted when there is no session.
        self::assertSame(422, $response->getStatusCode());
        self::assertCount(0, $this->written);
    }
}
