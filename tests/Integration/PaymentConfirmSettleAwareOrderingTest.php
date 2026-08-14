<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentConfirmation;
use Glueful\Extensions\Contracts\Payments\PaymentConfirmationHandler;
use Glueful\Extensions\Payvia\Database\Migrations\CreatePaymentIntentsTable;
use Glueful\Extensions\Payvia\Database\Migrations\CreateProviderEventsTable;
use Glueful\Extensions\Payvia\Events\PaymentProviderEvent;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;
use Glueful\Extensions\Payvia\Repositories\ProviderEventRepository;
use Glueful\Extensions\Payvia\Services\ConfirmationDispatcher;
use Glueful\Extensions\Payvia\Services\PaymentService;
use Glueful\Extensions\Payvia\Services\WebhookService;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

/**
 * `confirmAndRecord()` records the verify-origin provider event BEFORE running its own
 * confirmation dispatch. Once a host wires a strict-lane settlement listener (Thallo's
 * `WebhookOrderSettlementListener`), that first call already settles the order through the
 * nested webhook path -- so the route's own dispatch used to arrive at an
 * already-paid order and have the domain handler record a `payment_late_rejected` for a
 * payment that was never late, right on the manual-recovery timeline an operator reads.
 */
final class PaymentConfirmSettleAwareOrderingTest extends PayviaTestCase
{
    private PaymentIntentRepository $intents;
    private ProviderEventRepository $events;
    private OrderingOrderBook $book;
    private GatewayManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runMigration(new CreatePaymentIntentsTable());
        $this->runMigration(new CreateProviderEventsTable());

        $this->intents = new PaymentIntentRepository($this->connection);
        $this->events = new ProviderEventRepository($this->connection);
        $this->book = new OrderingOrderBook();

        $gateway = new OrderingConfirmGateway();
        $this->bind(OrderingConfirmGateway::class, $gateway);
        $this->manager = new GatewayManager($this->context->getContainer(), $this->context);
        $this->manager->registerDriver('paystack', OrderingConfirmGateway::class);
    }

    public function testNestedStrictLaneSettlementIsNotRecordedAsALatePayment(): void
    {
        $this->openIntent('ord_nested', 'ref_nested');

        // The strict lane settles THIS call's own reference from inside recordVerifyEvent().
        $webhooks = $this->webhooks($this->strictLaneSettlementListener());

        $result = $this->service($webhooks)->confirmAndRecord('ref_nested', 'paystack', [
            'payable_type' => 'commerce_order',
            'payable_id' => 'ord_nested',
        ]);

        self::assertSame('success', $result['payment_status']);
        self::assertSame(['ref_nested'], $this->book->settled);
        // The whole point: no spurious late rejection for the payment this very call confirmed.
        self::assertSame([], $this->book->lateRejected);

        $row = $this->intents->findByReference($this->context, 'paystack', 'ref_nested');
        self::assertIsArray($row);
        self::assertSame(PaymentIntentRepository::STATUS_CLOSED, (string) $row['status']);

        // Event recording is untouched by the skip: the verify-origin row is still stored and
        // processed exactly once.
        $stored = $this->events->findByDeliveryKey('paystack', 'verify:gw_ref_nested');
        self::assertIsArray($stored);
        self::assertSame('processed', (string) $stored['status']);
    }

    /**
     * The counter-case that keeps the fix honest: a GENUINELY late confirmation -- a second
     * attempt's own reference against an order some earlier attempt already paid -- is not
     * settled by the nested path (nothing closes THIS reference's intent there), so the route
     * still dispatches and the domain handler still records the late rejection.
     */
    public function testGenuinelyLateSecondSettlementIsStillRejected(): void
    {
        $this->openIntent('ord_late', 'ref_late_second');
        $this->book->markPaid('ord_late');

        $webhooks = $this->webhooks(static function (PaymentProviderEvent $event): void {
            unset($event);
        });

        $result = $this->service($webhooks)->confirmAndRecord('ref_late_second', 'paystack', [
            'payable_type' => 'commerce_order',
            'payable_id' => 'ord_late',
        ]);

        self::assertSame('success', $result['payment_status']);
        self::assertSame([], $this->book->settled);
        self::assertSame(['ref_late_second'], $this->book->lateRejected);
    }

    /** @return \Closure(PaymentProviderEvent):void */
    private function strictLaneSettlementListener(): \Closure
    {
        return function (PaymentProviderEvent $event): void {
            $reference = (string) ($event->event->normalized()['reference'] ?? '');
            $row = $this->intents->findByReference($this->context, 'paystack', $reference);
            if ($row === null) {
                return;
            }

            // Exactly what the strict-lane listener does: resolve the payable FROM the intent
            // row, settle the order, then close the intent.
            $this->book->settle((string) $row['payable_id'], $reference);
            $this->intents->settle($this->context, (string) $row['uuid']);
        };
    }

    /** @param callable(PaymentProviderEvent):void $dispatcher */
    private function webhooks(callable $dispatcher): WebhookService
    {
        return new WebhookService($this->context, $this->manager, $this->events, $dispatcher);
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

    private function service(WebhookService $webhooks): PaymentService
    {
        return new PaymentService(
            $this->context,
            new OrderingPaymentRepository(),
            $this->manager,
            $webhooks,
            new ConfirmationDispatcher($this->intents, [new OrderingHandler($this->book)]),
            $this->intents
        );
    }
}

final class OrderingConfirmGateway implements \Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface
{
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function verify(string $reference, array $options = []): array
    {
        return [
            'status' => 'success',
            'id' => 'gw_' . $reference,
            'reference' => $reference,
            'amount' => 4999,
            'currency' => 'USD',
        ];
    }
}

/**
 * Stands in for Commerce's order settlement: a second settlement of an already-paid order is
 * refused and recorded as a late payment, exactly like the real handler's
 * `payment_late_rejected` timeline event.
 */
final class OrderingOrderBook
{
    /** @var list<string> */
    public array $settled = [];

    /** @var list<string> */
    public array $lateRejected = [];

    /** @var array<string,true> */
    private array $paid = [];

    public function settle(string $payableId, string $reference): void
    {
        if (isset($this->paid[$payableId])) {
            $this->lateRejected[] = $reference;
            return;
        }

        $this->paid[$payableId] = true;
        $this->settled[] = $reference;
    }

    public function markPaid(string $payableId): void
    {
        $this->paid[$payableId] = true;
    }
}

final class OrderingPaymentRepository implements \Glueful\Extensions\Payvia\Contracts\PaymentRepositoryInterface
{
    public function getTableName(): string
    {
        return 'payments';
    }

    /** @param array<string,mixed> $data */
    public function createPayment(ApplicationContext $context, array $data): string
    {
        return 'pay_ordering_1';
    }

    /** @return array<string,mixed>|null */
    public function findByReference(ApplicationContext $context, string $reference): ?array
    {
        return null;
    }

    /** @param array<string,mixed> $data */
    public function updateByReference(ApplicationContext $context, string $reference, array $data): bool
    {
        return true;
    }
}

final class OrderingHandler implements PaymentConfirmationHandler
{
    public function __construct(private OrderingOrderBook $book)
    {
    }

    public function supports(string $payableType): bool
    {
        return $payableType === 'commerce_order';
    }

    public function confirmed(
        ApplicationContext $context,
        PayableReference $payable,
        PaymentConfirmation $confirmation
    ): void {
        unset($context);
        $this->book->settle($payable->id, $confirmation->reference);
    }
}
