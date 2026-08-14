<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Auth\AuthenticationManager;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentConfirmation;
use Glueful\Extensions\Contracts\Payments\PaymentConfirmationHandler;
use Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface;
use Glueful\Extensions\Payvia\Contracts\PaymentRepositoryInterface;
use Glueful\Extensions\Payvia\Controllers\PaymentController;
use Glueful\Extensions\Payvia\Database\Migrations\CreatePaymentIntentsTable;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;
use Glueful\Extensions\Payvia\Services\ConfirmationDispatcher;
use Glueful\Extensions\Payvia\Services\PayableAttributionException;
use Glueful\Extensions\Payvia\Services\PaymentService;
use Glueful\Extensions\Payvia\Tests\Support\FixedTenantResolver;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Security: `/payvia/payments/confirm` must not let an authenticated caller attribute a
 * reference to a payable that reference does not belong to. Commerce's own handler refuses a
 * MISMATCHED amount, but two equal-amount, equal-currency pending orders sail straight
 * through that guard -- the only authority that can tell them apart is the `payment_intents`
 * row that actually owns the reference.
 */
final class PaymentConfirmPayableBindingTest extends PayviaTestCase
{
    private PaymentIntentRepository $intents;
    private BindingRecordingHandler $handler;
    private BindingPaymentRepository $payments;
    private BindingConfirmGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runMigration(new CreatePaymentIntentsTable());
        $this->intents = new PaymentIntentRepository($this->connection);
        $this->handler = new BindingRecordingHandler('commerce_order');
        $this->payments = new BindingPaymentRepository();
        $this->gateway = new BindingConfirmGateway('success', 4999, 'USD');
    }

    public function testEqualAmountCrossOrderAttributionIsRefusedBeforeAnyDispatchOrSettle(): void
    {
        $this->openIntent('ord_victim', 'ref_victim');

        try {
            $this->service()->confirmAndRecord('ref_victim', 'paystack', [
                'payable_type' => 'commerce_order',
                // The attacker's OWN pending order, priced identically to the victim's --
                // the amount/currency guard downstream cannot tell these two apart.
                'payable_id' => 'ord_attacker',
            ]);
            self::fail('Expected a payable attribution refusal.');
        } catch (PayableAttributionException $e) {
            self::assertStringContainsString(PayableAttributionException::MARKER, $e->getMessage());
            // Non-revealing: the refusal never discloses which payable actually owns the
            // reference, only that the supplied one does not.
            self::assertStringNotContainsString('ord_victim', $e->getMessage());
        }

        // No confirmation dispatch...
        self::assertSame([], $this->handler->calls);
        // ...no settle (the victim's attempt stays exactly as it was)...
        $row = $this->intents->findByReference($this->context, 'paystack', 'ref_victim');
        self::assertIsArray($row);
        self::assertSame(PaymentIntentRepository::STATUS_OPEN, (string) $row['status']);
        self::assertSame('ord_victim', (string) $row['payable_id']);
        // ...and nothing written to `payments` under the attacker's attribution.
        self::assertSame([], $this->payments->written);
    }

    /**
     * The refusal runs BEFORE the `payments` upsert, so it also protects the stored row's own
     * attribution: a mismatched confirm of a FAILED verification would otherwise rewrite the
     * existing row's `payable_type`/`payable_id` (upsert is keyed by reference alone).
     */
    public function testRefusalPersistsNothingEvenWhenTheGatewayReportsFailure(): void
    {
        $this->openIntent('ord_victim', 'ref_victim');
        $this->gateway->status = 'failed';

        $this->expectException(PayableAttributionException::class);

        try {
            $this->service()->confirmAndRecord('ref_victim', 'paystack', [
                'payable_type' => 'commerce_order',
                'payable_id' => 'ord_attacker',
            ]);
        } finally {
            self::assertSame([], $this->payments->written);
        }
    }

    public function testConfirmingWithThePayableTheReferenceIsBoundToIsUnchanged(): void
    {
        $this->openIntent('ord_owner', 'ref_owner');

        $result = $this->service()->confirmAndRecord('ref_owner', 'paystack', [
            'payable_type' => 'commerce_order',
            'payable_id' => 'ord_owner',
        ]);

        self::assertSame('success', $result['payment_status']);
        self::assertCount(1, $this->handler->calls);
        self::assertSame('ord_owner', $this->handler->calls[0]['payable']->id);

        $row = $this->intents->findByReference($this->context, 'paystack', 'ref_owner');
        self::assertIsArray($row);
        self::assertSame(PaymentIntentRepository::STATUS_CLOSED, (string) $row['status']);
    }

    /**
     * A reference with NO intent row keeps today's behavior: payvia's own hosted-checkout
     * lane is not the only way a reference comes into existence -- legacy rows predating
     * `payment_intents`, and manual/operator-originated references created directly at the
     * provider, never have one. There is no binding to compare against, so there is nothing
     * to refuse.
     */
    public function testReferenceWithNoIntentRowKeepsTodaysBehaviour(): void
    {
        $result = $this->service()->confirmAndRecord('ref_manual', 'paystack', [
            'payable_type' => 'commerce_order',
            'payable_id' => 'ord_manual',
        ]);

        self::assertSame('success', $result['payment_status']);
        self::assertCount(1, $this->handler->calls);
        self::assertSame('ord_manual', $this->handler->calls[0]['payable']->id);
    }

    /**
     * Cross-tenant stays non-revealing: the lookup is tenant-scoped, so another tenant's
     * intent row is invisible here and the call is indistinguishable from the no-intent-row
     * case above -- never a refusal that would confirm the reference exists elsewhere.
     */
    public function testAnotherTenantsIntentRowIsNeitherConsultedNorRevealed(): void
    {
        $otherTenant = new PaymentIntentRepository(
            $this->connection,
            null,
            new FixedTenantResolver('tenantOTHER1')
        );
        $otherTenant->createOpen($this->context, [
            'payable_type' => 'commerce_order',
            'payable_id' => 'ord_other_tenant',
            'gateway' => 'paystack',
            'reference' => 'ref_other_tenant',
            'amount' => 4999,
            'currency' => 'USD',
        ]);

        $result = $this->service()->confirmAndRecord('ref_other_tenant', 'paystack', [
            'payable_type' => 'commerce_order',
            'payable_id' => 'ord_mine',
        ]);

        self::assertSame('success', $result['payment_status']);
        self::assertCount(1, $this->handler->calls);
    }

    /**
     * The refusal is a conflict, not a fault: it must not surface through the controller's
     * generic catch-all as a 500.
     */
    public function testTheConfirmRouteAnswersAMismatchedAttributionWithAConflict(): void
    {
        $this->openIntent('ord_victim', 'ref_victim');

        $this->bind(AuthenticationManager::class, $this->createMock(AuthenticationManager::class));
        $this->bind(Request::class, new Request());
        $controller = new PaymentController($this->context, $this->service());

        $response = $controller->confirm(new Request([], [], [], [], [], [], json_encode([
            'reference' => 'ref_victim',
            'gateway' => 'paystack',
            'payable_type' => 'commerce_order',
            'payable_id' => 'ord_attacker',
        ], JSON_THROW_ON_ERROR)));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame([], $this->handler->calls);
        self::assertSame([], $this->payments->written);
    }

    /**
     * A caller that supplies no payable at all has nothing to compare -- and already
     * dispatches nothing. Unchanged.
     */
    public function testCallerWithoutAPayableIsUnaffected(): void
    {
        $this->openIntent('ord_owner', 'ref_owner');

        $result = $this->service()->confirmAndRecord('ref_owner', 'paystack', []);

        self::assertSame('success', $result['payment_status']);
        self::assertSame([], $this->handler->calls);
        self::assertCount(1, $this->payments->written);
    }

    private function openIntent(string $payableId, string $reference): void
    {
        $this->intents->createOpen($this->context, [
            'payable_type' => 'commerce_order',
            'payable_id' => $payableId,
            'gateway' => 'paystack',
            'reference' => $reference,
            'amount' => 4999,
            'currency' => 'USD',
        ]);
    }

    private function service(): PaymentService
    {
        $this->bind(BindingConfirmGateway::class, $this->gateway);
        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver('paystack', BindingConfirmGateway::class);

        return new PaymentService(
            $this->context,
            $this->payments,
            $manager,
            null,
            new ConfirmationDispatcher($this->intents, [$this->handler]),
            $this->intents
        );
    }
}

final class BindingConfirmGateway implements PaymentGatewayInterface
{
    public function __construct(
        public string $status,
        private int $amount,
        private string $currency,
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function verify(string $reference, array $options = []): array
    {
        return [
            'status' => $this->status,
            'id' => 'gw_' . $reference,
            'reference' => $reference,
            'amount' => $this->amount,
            'currency' => $this->currency,
        ];
    }
}

final class BindingRecordingHandler implements PaymentConfirmationHandler
{
    /** @var list<array{payable: PayableReference, confirmation: PaymentConfirmation}> */
    public array $calls = [];

    public function __construct(private string $supportedType)
    {
    }

    public function supports(string $payableType): bool
    {
        return $payableType === $this->supportedType;
    }

    public function confirmed(
        ApplicationContext $context,
        PayableReference $payable,
        PaymentConfirmation $confirmation
    ): void {
        unset($context);
        $this->calls[] = ['payable' => $payable, 'confirmation' => $confirmation];
    }
}

final class BindingPaymentRepository implements PaymentRepositoryInterface
{
    /** @var list<array<string,mixed>> */
    public array $written = [];

    public function getTableName(): string
    {
        return 'payments';
    }

    /** @param array<string,mixed> $data */
    public function createPayment(ApplicationContext $context, array $data): string
    {
        $this->written[] = $data;
        return 'pay_binding_1';
    }

    /** @return array<string,mixed>|null */
    public function findByReference(ApplicationContext $context, string $reference): ?array
    {
        return null;
    }

    /** @param array<string,mixed> $data */
    public function updateByReference(ApplicationContext $context, string $reference, array $data): bool
    {
        $this->written[] = $data;
        return true;
    }
}
