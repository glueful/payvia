<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentConfirmation;
use Glueful\Extensions\Contracts\Payments\PaymentConfirmationHandler;
use Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface;
use Glueful\Extensions\Payvia\Contracts\PaymentRepositoryInterface;
use Glueful\Extensions\Payvia\Database\Migrations\CreatePaymentIntentsTable;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\PayviaServiceProvider;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;
use Glueful\Extensions\Payvia\Services\ConfirmationDispatcher;
use Glueful\Extensions\Payvia\Services\PaymentService;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;

final class ConfirmationDispatchTest extends PayviaTestCase
{
    private PaymentIntentRepository $intents;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runMigration(new CreatePaymentIntentsTable());
        $this->intents = new PaymentIntentRepository($this->connection);
    }

    public function testDispatchReachesMatchingHandlerOnSuccessOnly(): void
    {
        $handler = new RecordingHandler('commerce_order');
        $gateway = new FakeConfirmGateway('failed', 49.99, 'USD');
        $this->bind(FakeConfirmGateway::class, $gateway);
        $service = $this->service($handler);

        $service->confirmAndRecord('ref_failed', 'paystack', [
            'payable_type' => 'commerce_order',
            'payable_id' => 'ord1',
        ]);
        self::assertSame([], $handler->calls);

        $gateway->status = 'success';
        $service->confirmAndRecord('ref_success', 'paystack', [
            'payable_type' => 'commerce_order',
            'payable_id' => 'ord1',
        ]);

        self::assertCount(1, $handler->calls);
        self::assertSame(4999, $handler->calls[0]['confirmation']->amount);
        self::assertSame('paid', $handler->calls[0]['confirmation']->status);
        self::assertSame('USD', $handler->calls[0]['confirmation']->currency);
    }

    public function testNonMatchingHandlerIsSkipped(): void
    {
        $handler = new RecordingHandler('lemma_invoice');
        $service = $this->service($handler);

        $this->bind(FakeConfirmGateway::class, new FakeConfirmGateway('success', 49.99, 'USD'));
        $service->confirmAndRecord('ref_success', 'paystack', [
            'payable_type' => 'commerce_order',
            'payable_id' => 'ord2',
        ]);

        self::assertSame([], $handler->calls);
    }

    public function testSuccessfulConfirmationClosesTheOpenIntent(): void
    {
        $handler = new RecordingHandler('commerce_order');
        $service = $this->service($handler);

        $this->intents->createOpen($this->context, [
            'payable_type' => 'commerce_order',
            'payable_id' => 'ord3',
            'gateway' => 'paystack',
            'reference' => 'ref_success',
            'amount' => 4999,
            'currency' => 'USD',
            'payload' => ['checkout_url' => 'https://checkout.test/ref_success'],
        ]);

        $this->bind(FakeConfirmGateway::class, new FakeConfirmGateway('success', 49.99, 'USD'));
        $service->confirmAndRecord('ref_success', 'paystack', [
            'payable_type' => 'commerce_order',
            'payable_id' => 'ord3',
        ]);

        self::assertNull($this->intents->findOpen($this->context, 'commerce_order', 'ord3'));
    }

    public function testProviderBuildsConfirmationDispatcherThroughFactory(): void
    {
        $services = PayviaServiceProvider::services();

        self::assertSame([PayviaServiceProvider::class, 'makeConfirmationDispatcher'], (
            $services[ConfirmationDispatcher::class]['factory'] ?? null
        ));
    }

    private function service(PaymentConfirmationHandler ...$handlers): PaymentService
    {
        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver('paystack', FakeConfirmGateway::class);

        return new PaymentService(
            $this->context,
            new RecordingPaymentRepository(),
            $manager,
            null,
            new ConfirmationDispatcher($this->intents, $handlers)
        );
    }
}

final class FakeConfirmGateway implements PaymentGatewayInterface
{
    public function __construct(
        public string $status,
        private float $amount,
        private string $currency,
    ) {
    }

    public function verify(string $reference, array $options = []): array
    {
        return [
            'status' => $this->status,
            'id' => 'gw_' . $reference,
            'reference' => $reference,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'raw' => ['provider' => 'fake'],
        ];
    }
}

final class RecordingHandler implements PaymentConfirmationHandler
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
        $this->calls[] = [
            'payable' => $payable,
            'confirmation' => $confirmation,
        ];
    }
}

final class RecordingPaymentRepository implements PaymentRepositoryInterface
{
    public function getTableName(): string
    {
        return 'payments';
    }

    public function createPayment(array $data): string
    {
        return 'pay1';
    }

    public function findByReference(string $reference): ?array
    {
        return null;
    }

    public function updateByReference(string $reference, array $data): bool
    {
        return true;
    }
}
