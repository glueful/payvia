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
        // Wire amounts are already integer minor units; 4999 = USD 49.99, passed
        // straight through with no float conversion.
        $gateway = new FakeConfirmGateway('failed', 4999, 'USD');
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

        $this->bind(FakeConfirmGateway::class, new FakeConfirmGateway('success', 4999, 'USD'));
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

        $this->bind(FakeConfirmGateway::class, new FakeConfirmGateway('success', 4999, 'USD'));
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

    // ==================================================================
    // Payment-links Task 3 -- reference-addressable confirmation. A payable can carry a
    // retired (superseded/closed/failed) attempt alongside its current open one, each under
    // its OWN provider reference; a webhook must settle the EXACT row its reference
    // addresses, never "whichever attempt happens to be open" for the payable.
    // ==================================================================

    public function testConfirmationForTheSupersededAttemptClosesOnlyThatRowLeavingTheOpenAttemptUntouched(): void
    {
        $handler = new RecordingHandler('commerce_order');
        $service = $this->service($handler);

        [$openA, $openB] = $this->createSupersededAndOpenAttempts('ord-supersede-1');

        $this->bind(FakeConfirmGateway::class, new FakeConfirmGateway('success', 4999, 'USD'));
        $service->confirmAndRecord('ref_old', 'paystack', [
            'payable_type' => 'commerce_order',
            'payable_id' => 'ord-supersede-1',
        ]);

        $closedA = $this->intents->findByUuid($this->context, (string) $openA['uuid']);
        self::assertIsArray($closedA);
        self::assertSame('closed', $closedA['status']);

        $stillOpenB = $this->intents->findOpen($this->context, 'commerce_order', 'ord-supersede-1');
        self::assertIsArray($stillOpenB);
        self::assertSame((string) $openB['uuid'], (string) $stillOpenB['uuid']);
        self::assertSame('ref_new', $stillOpenB['reference']);
    }

    public function testConfirmationForTheOpenAttemptClosesOnlyThatRowLeavingTheSupersededAttemptUntouched(): void
    {
        $handler = new RecordingHandler('commerce_order');
        $service = $this->service($handler);

        [$openA, $openB] = $this->createSupersededAndOpenAttempts('ord-supersede-2');

        $this->bind(FakeConfirmGateway::class, new FakeConfirmGateway('success', 4999, 'USD'));
        $service->confirmAndRecord('ref_new', 'paystack', [
            'payable_type' => 'commerce_order',
            'payable_id' => 'ord-supersede-2',
        ]);

        self::assertNull($this->intents->findOpen($this->context, 'commerce_order', 'ord-supersede-2'));

        $closedB = $this->intents->findByUuid($this->context, (string) $openB['uuid']);
        self::assertIsArray($closedB);
        self::assertSame('closed', $closedB['status']);

        // The already-retired sibling is never touched by an unrelated reference's
        // confirmation -- and never resurrected back to open or closed a second time.
        $stillSupersededA = $this->intents->findByUuid($this->context, (string) $openA['uuid']);
        self::assertIsArray($stillSupersededA);
        self::assertSame('superseded', $stillSupersededA['status']);
    }

    public function testConfirmationForAnUnmatchedReferenceIsANoOpJustLikeBeforeThisFix(): void
    {
        $handler = new RecordingHandler('commerce_order');
        $service = $this->service($handler);

        $this->bind(FakeConfirmGateway::class, new FakeConfirmGateway('success', 4999, 'USD'));
        $service->confirmAndRecord('ref_unmatched', 'paystack', [
            'payable_type' => 'commerce_order',
            'payable_id' => 'ord-unmatched',
        ]);

        // No intent row exists anywhere under this reference -- there is nothing to close,
        // exactly as the pre-fix findOpen(payable) lookup found nothing for a payable with
        // no open intent. Handlers still fire; only the intent-closing side is a no-op.
        self::assertCount(1, $handler->calls);
        self::assertNull($this->intents->findOpen($this->context, 'commerce_order', 'ord-unmatched'));
    }

    public function testDispatchSettlesExactlyTheRowMatchingTenantGatewayAndReference(): void
    {
        $handler = new RecordingHandler('commerce_order');
        $dispatcher = new ConfirmationDispatcher($this->intents, [$handler]);

        [$openA, $openB] = $this->createSupersededAndOpenAttempts('ord-exact');

        $dispatcher->dispatch(
            $this->context,
            new PayableReference('commerce_order', 'ord-exact', 4999, 'USD'),
            new PaymentConfirmation('paid', 'ref_old', 4999, 'USD'),
            'paystack'
        );

        self::assertCount(1, $handler->calls);
        self::assertSame('ref_old', $handler->calls[0]['confirmation']->reference);

        $closedA = $this->intents->findByUuid($this->context, (string) $openA['uuid']);
        self::assertIsArray($closedA);
        self::assertSame('closed', $closedA['status']);

        $stillOpenB = $this->intents->findOpen($this->context, 'commerce_order', 'ord-exact');
        self::assertIsArray($stillOpenB);
        self::assertSame((string) $openB['uuid'], (string) $stillOpenB['uuid']);
    }

    /**
     * Builds a payable with a retired attempt A (`ref_old`, superseded) alongside its
     * current open attempt B (`ref_new`) -- the exact shape a reference-scoped webhook
     * lookup must disambiguate between.
     *
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}
     */
    private function createSupersededAndOpenAttempts(string $payableId): array
    {
        $this->intents->createOpen($this->context, [
            'payable_type' => 'commerce_order',
            'payable_id' => $payableId,
            'gateway' => 'paystack',
            'reference' => 'ref_old',
            'amount' => 4999,
            'currency' => 'USD',
        ]);
        $openA = $this->intents->findOpen($this->context, 'commerce_order', $payableId);
        self::assertIsArray($openA);
        self::assertTrue($this->intents->supersede($this->context, (string) $openA['uuid']));

        $this->intents->createOpen($this->context, [
            'payable_type' => 'commerce_order',
            'payable_id' => $payableId,
            'gateway' => 'paystack',
            'reference' => 'ref_new',
            'amount' => 4999,
            'currency' => 'USD',
        ]);
        $openB = $this->intents->findOpen($this->context, 'commerce_order', $payableId);
        self::assertIsArray($openB);

        return [$openA, $openB];
    }

    /**
     * Guard-side regression: a stale compiled container that resolves this service via the
     * pre-2.7.0, five-argument constructor must fail LOUD rather than silently run every
     * pre-existing scenario above with the payable-binding/settle-aware guards disabled.
     */
    public function testFiveArgumentConstructionFailsLoudInsteadOfSilentlyDisablingTheGuards(): void
    {
        $manager = new GatewayManager($this->context->getContainer(), $this->context);
        $manager->registerDriver('paystack', FakeConfirmGateway::class);
        $this->bind(FakeConfirmGateway::class, new FakeConfirmGateway('success', 4999, 'USD'));

        // Exactly the pre-2.7.0 signature: five positional arguments, no PaymentIntentRepository.
        $service = new PaymentService(
            $this->context,
            new RecordingPaymentRepository(),
            $manager,
            null,
            new ConfirmationDispatcher($this->intents, [])
        );

        $this->expectException(\LogicException::class);
        $service->confirmAndRecord('ref_stale_container', 'paystack');
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
            new ConfirmationDispatcher($this->intents, $handlers),
            $this->intents
        );
    }
}

final class FakeConfirmGateway implements PaymentGatewayInterface
{
    public function __construct(
        public string $status,
        private int $amount,
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

    public function createPayment(ApplicationContext $context, array $data): string
    {
        return 'pay1';
    }

    public function findByReference(ApplicationContext $context, string $reference): ?array
    {
        return null;
    }

    public function updateByReference(ApplicationContext $context, string $reference, array $data): bool
    {
        return true;
    }
}
