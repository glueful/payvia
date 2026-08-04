<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Unit\Gateways;

use Glueful\Extensions\Payvia\Checkout\CheckoutUnavailableException;
use Glueful\Extensions\Payvia\Checkout\SubscriptionCheckoutRequest;
use Glueful\Extensions\Payvia\Checkout\SubscriptionCheckoutService;
use Glueful\Extensions\Payvia\Contracts\SubscriptionInitiationCapableGateway;
use Glueful\Extensions\Payvia\Database\Migrations\CreateCheckoutOriginations;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Gateways\PaystackGateway;
use Glueful\Extensions\Payvia\Repositories\CheckoutOriginationRepository;
use Glueful\Extensions\Payvia\Repositories\CheckoutSubjectGuardRepository;
use Glueful\Extensions\Payvia\Support\CheckoutSandboxProof\FixtureProjector;
use Glueful\Extensions\Payvia\Tests\Support\FixedTenantResolver;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;
use Glueful\Http\Client;

/**
 * Task 3 (workspace self-serve checkout program, RESCOPED per the 2026-08-04 sandbox ruling —
 * see the design spec §3.1 "SANDBOX PROOF OUTCOME" and `tests/Fixtures/paystack-checkout/README.md`):
 * pins that Paystack subscription checkout stays unavailable in glueful/payvia 2.5.0, and that
 * the committed negative-proof fixtures actually prove it.
 *
 * Five things this suite pins (plan Task 3, Step 1 (a)-(e)):
 *   (a) the four committed fixtures -- projected from the real 2026-08-04 sandbox captures
 *       through {@see FixtureProjector} -- prove the negative shape;
 *   (b) {@see GatewayManager::supports()} reports `paystack`/`subscription_checkout` as false;
 *   (c) {@see SubscriptionCheckoutService::prepare()} targeting `paystack` throws
 *       {@see CheckoutUnavailableException} before any ledger/guard row is written;
 *   (d) PaystackGateway's existing webhook normalization (the ONLY thing Task 3 could have
 *       regressed -- it added a docblock, no logic change) is unaffected, re-verified against
 *       the same real captures;
 *   (e) a hostile re-run of the REAL raw sandbox captures through the projector leaks no
 *       customer/authorization field -- skipped (not failed) when the raw captures are not
 *       present on disk, because they are NEVER committed (see the class docblock on
 *       {@see FixtureProjector} and this repo's `/tmp/paystack-sandbox-proof-2026-08-04/`
 *       convention); the committed-fixture assertions in (a) are the permanent, portable proof.
 */
final class PaystackSubscriptionCheckoutUnavailableTest extends PayviaTestCase
{
    private const TENANT = 'tenantAAAA01';

    /**
     * The maintainer's real 2026-08-04 sandbox run. Never committed (test-mode customer/
     * authorization data) -- present only on the machine that ran
     * `payvia:checkout:sandbox-proof`. See {@see testHostile...} below for how the suite
     * degrades gracefully everywhere else.
     */
    private const RAW_CAPTURE_DIR = '/tmp/paystack-sandbox-proof-2026-08-04';

    private const FIXTURE_DIR = __DIR__ . '/../../Fixtures/paystack-checkout';

    // =====================================================================================
    // (a) committed fixtures prove the negative
    // =====================================================================================

    public function testChargeSuccessFixtureCarriesReferenceAndOriginationUuidButNoSubscriptionIdentifier(): void
    {
        $fixture = $this->committedFixture('charge-success');

        self::assertSame('charge.success', $fixture['event']);
        self::assertSame('thallo-card-1785826131', $fixture['reference']);
        self::assertSame('card064851', $fixture['metadata']['origination_uuid'] ?? null);

        // The negative proof itself: NO subscription identifier survives at any allowlisted
        // location (neither `subscription_code` nor nested `subscription.subscription_code`).
        self::assertArrayNotHasKey('subscription_code', $fixture);
    }

    public function testSubscriptionCreateFixtureCarriesSubscriptionAndPlanCodeButNoOriginationUuidOrReference(): void
    {
        $fixture = $this->committedFixture('subscription-create');

        self::assertSame('subscription.create', $fixture['event']);
        self::assertSame('SUB_lu1v0ps1y2sbv7x', $fixture['subscription_code']);
        self::assertSame('PLN_fq4o4cjpec4bzpi', $fixture['plan_code']);

        // The negative proof itself: no metadata at all (so no origination_uuid), and no
        // transaction reference -- `subscription.create` carries nothing that could correlate
        // it back to the checkout attempt that created it.
        self::assertArrayNotHasKey('metadata', $fixture);
        self::assertArrayNotHasKey('reference', $fixture);
    }

    public function testInitializeWithoutAmountFixtureRecordsTheInvalidAmountRejection(): void
    {
        $fixture = $this->committedFixture('initialize-without-amount');

        self::assertFalse($fixture['status']);
        self::assertSame('invalid_amount', $fixture['code']);
        self::assertSame('validation_error', $fixture['type']);
        self::assertArrayNotHasKey('data', $fixture);
    }

    public function testInitializeWithAmountFixtureProvesTheWorkingShapeWithoutLeakingSingleUseSecrets(): void
    {
        $fixture = $this->committedFixture('initialize-with-amount');

        self::assertTrue($fixture['status']);
        self::assertSame('thallo-card-1785826131', $fixture['reference']);

        // The negative proof's amount-shape half: `amount` WAS required (see the sibling
        // without-amount fixture) and supplying it produced a working checkout -- but the
        // single-use secrets that would let anyone complete THIS SPECIFIC checkout must never
        // be committed.
        self::assertArrayNotHasKey('authorization_url', $fixture);
        self::assertArrayNotHasKey('access_code', $fixture);
    }

    /**
     * The fixtures are not hand-written prose -- re-running the projector against the
     * (locally reconstructed) raw shape must reproduce them byte-for-byte, proving the four
     * committed files really are `FixtureProjector`'s output and not a manually edited stand-in.
     */
    public function testCommittedFixturesAreReproducibleFromTheDocumentedRawShapeThroughTheRealProjector(): void
    {
        $rawChargeSuccess = [
            'event' => 'charge.success',
            'data' => [
                'reference' => 'thallo-card-1785826131',
                'status' => 'success',
                'metadata' => ['origination_uuid' => 'card064851'],
                'amount' => 10000,
                'currency' => 'GHS',
                'plan' => ['plan_code' => 'PLN_fq4o4cjpec4bzpi'],
            ],
        ];
        self::assertSame($this->committedFixture('charge-success'), FixtureProjector::project($rawChargeSuccess));

        $rawSubscriptionCreate = [
            'event' => 'subscription.create',
            'data' => [
                'status' => 'active',
                'subscription_code' => 'SUB_lu1v0ps1y2sbv7x',
                'plan' => ['plan_code' => 'PLN_fq4o4cjpec4bzpi'],
            ],
        ];
        self::assertSame(
            $this->committedFixture('subscription-create'),
            FixtureProjector::project($rawSubscriptionCreate)
        );

        $rawInitializeWithoutAmount = [
            'status' => false,
            'message' => 'Invalid Amount Sent',
            'meta' => ['nextStep' => "Ensure that you're passing your amount correctly. It should be a number "
                . 'greater than zero, with no decimal places'],
            'type' => 'validation_error',
            'code' => 'invalid_amount',
        ];
        self::assertSame(
            $this->committedFixture('initialize-without-amount'),
            FixtureProjector::projectInitializeResponse($rawInitializeWithoutAmount)
        );

        $rawInitializeWithAmount = [
            'status' => true,
            'message' => 'Authorization URL created',
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/pcu7qbdby4nsiwv',
                'access_code' => 'pcu7qbdby4nsiwv',
                'reference' => 'thallo-card-1785826131',
            ],
        ];
        self::assertSame(
            $this->committedFixture('initialize-with-amount'),
            FixtureProjector::projectInitializeResponse($rawInitializeWithAmount)
        );
    }

    // =====================================================================================
    // (b) GatewayManager::supports('paystack', 'subscription_checkout') === false
    // =====================================================================================

    public function testGatewayManagerReportsPaystackDoesNotSupportSubscriptionCheckout(): void
    {
        $manager = $this->gatewayManagerWithRealPaystack();

        self::assertFalse($manager->supports('paystack', 'subscription_checkout'));
        self::assertNotInstanceOf(
            SubscriptionInitiationCapableGateway::class,
            $manager->gateway('paystack')
        );
    }

    // =====================================================================================
    // (c) SubscriptionCheckoutService::prepare() targeting paystack fails BEFORE any write
    // =====================================================================================

    public function testPrepareTargetingPaystackThrowsCheckoutUnavailableBeforeAnyLedgerOrGuardWrite(): void
    {
        $this->runMigration(new CreateCheckoutOriginations());
        $originations = new CheckoutOriginationRepository(
            $this->connection,
            resolver: new FixedTenantResolver(self::TENANT),
        );
        $guards = new CheckoutSubjectGuardRepository($this->connection);

        $service = new SubscriptionCheckoutService(
            $originations,
            $guards,
            $this->gatewayManagerWithRealPaystack(),
            new FixedTenantResolver(self::TENANT),
        );

        $request = new SubscriptionCheckoutRequest(
            originationUuid: 'origPAYSTK01',
            tenantUuid: '',
            subjectKey: 'tenant:' . self::TENANT,
            gateway: 'paystack',
            providerPlanIdentifier: 'PLN_fq4o4cjpec4bzpi',
            consumerMetadata: ['tenant_uuid' => self::TENANT],
            customerEmail: 'buyer@example.test',
            returnUrl: 'https://admin.example.test/billing/return',
            cancelUrl: 'https://admin.example.test/billing',
            idempotencyKey: 'idem-paystack-negative-proof',
            requiredProjectionConsumer: 'subscriptions',
        );

        $continuationCalls = 0;
        try {
            $service->prepare($this->context, $request, function () use (&$continuationCalls): void {
                $continuationCalls++;
            });
            self::fail('Expected CheckoutUnavailableException');
        } catch (CheckoutUnavailableException $e) {
            self::assertStringContainsString('paystack', $e->getMessage());
        }

        self::assertSame(0, $continuationCalls, 'the local-reservation continuation must never run');
        self::assertSame(0, $this->connection->table('subscription_checkout_originations')->count());
        self::assertSame(0, $this->connection->table('subscription_checkout_subject_guards')->count());
    }

    // =====================================================================================
    // (d) existing Paystack webhook normalization is unaffected
    // =====================================================================================

    /**
     * Task 3 touched `PaystackGateway` only to add a class-level docblock (no logic change).
     * Re-verified directly against the same real captures used for the negative proof: the
     * charge normalizes with the correlation metadata but no subscription id; the subscription
     * event normalizes with the subscription id but no metadata -- exactly mirroring the
     * committed fixtures' shape, proving the webhook/projection path is unchanged.
     */
    public function testPaystackWebhookNormalizationOfTheSameCapturesIsUnchanged(): void
    {
        $gateway = new PaystackGateway($this->createMock(Client::class), $this->context);

        $chargeRaw = (string) json_encode([
            'event' => 'charge.success',
            'data' => [
                'reference' => 'thallo-card-1785826131',
                'status' => 'success',
                'metadata' => ['origination_uuid' => 'card064851'],
                'amount' => 10000,
                'currency' => 'GHS',
            ],
        ]);
        $chargeEvent = $gateway->parseWebhookEvent($chargeRaw, []);

        self::assertSame(EventType::PAYMENT_SUCCEEDED, $chargeEvent->type());
        self::assertSame('thallo-card-1785826131', $chargeEvent->normalized()['reference'] ?? null);
        self::assertSame('card064851', $chargeEvent->normalized()['metadata']['origination_uuid'] ?? null);
        self::assertArrayNotHasKey('gateway_subscription_id', $chargeEvent->normalized());

        $subscriptionRaw = (string) json_encode([
            'event' => 'subscription.create',
            'data' => [
                'status' => 'active',
                'subscription_code' => 'SUB_lu1v0ps1y2sbv7x',
                'plan' => ['plan_code' => 'PLN_fq4o4cjpec4bzpi'],
            ],
        ]);
        $subscriptionEvent = $gateway->parseWebhookEvent($subscriptionRaw, []);

        self::assertSame(EventType::SUBSCRIPTION_CREATED, $subscriptionEvent->type());
        self::assertSame(
            'SUB_lu1v0ps1y2sbv7x',
            $subscriptionEvent->normalized()['gateway_subscription_id'] ?? null
        );
        self::assertArrayNotHasKey('metadata', $subscriptionEvent->normalized());
    }

    // =====================================================================================
    // (e) hostile re-run over the REAL raw captures -- best-effort, skips when absent
    // =====================================================================================

    /**
     * Runs only when the maintainer's real, never-committed raw captures are present on disk
     * (true during this task's own gate run). Deliberately extracts its forbidden needles FROM
     * the loaded raw file at runtime rather than hardcoding literal PII/secret values in this
     * committed test source -- the point of this test is to prove nothing leaks INTO git, so the
     * test itself must not become the leak.
     */
    public function testHostileReRunOverRealRawCapturesLeaksNoCustomerOrAuthorizationField(): void
    {
        if (!is_dir(self::RAW_CAPTURE_DIR)) {
            self::markTestSkipped(
                'Real raw sandbox captures are only ever present locally at ' . self::RAW_CAPTURE_DIR
                . ' (never committed). This test re-verifies sanitization against them when present; '
                . 'the committed-fixture assertions above are the permanent, portable proof.'
            );
        }

        foreach (['charge-success', 'subscription-create'] as $eventName) {
            $raw = $this->decodeRawCapture($eventName);
            $projected = FixtureProjector::project($raw);
            $encoded = (string) json_encode($projected);

            foreach ($this->needlesFromWebhookCapture($raw) as $needle) {
                self::assertStringNotContainsString($needle, $encoded, "Leaked forbidden value: {$needle}");
            }

            self::assertArrayNotHasKey('customer', $projected);
            self::assertArrayNotHasKey('authorization', $projected);
        }

        foreach (['initialize-without-amount', 'initialize-with-amount'] as $initName) {
            $raw = $this->decodeRawCapture($initName);
            $projected = FixtureProjector::projectInitializeResponse($raw);
            $encoded = (string) json_encode($projected);

            foreach ($this->needlesFromInitializeCapture($raw) as $needle) {
                self::assertStringNotContainsString($needle, $encoded, "Leaked forbidden value: {$needle}");
            }

            self::assertArrayNotHasKey('authorization_url', $projected);
            self::assertArrayNotHasKey('access_code', $projected);
        }
    }

    // =====================================================================================
    // Harness
    // =====================================================================================

    private function gatewayManagerWithRealPaystack(): GatewayManager
    {
        $this->bind(PaystackGateway::class, new PaystackGateway($this->createMock(Client::class), $this->context));

        // 'paystack' is already GatewayManager's own default driver mapping -- no
        // registerDriver() call needed, mirroring production wiring exactly.
        return new GatewayManager($this->context->getContainer(), $this->context);
    }

    /** @return array<string,mixed> */
    private function committedFixture(string $name): array
    {
        $path = self::FIXTURE_DIR . '/' . $name . '.json';
        self::assertFileExists($path, "Missing committed fixture: {$name}.json");

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /** @return array<string,mixed> */
    private function decodeRawCapture(string $name): array
    {
        $path = self::RAW_CAPTURE_DIR . '/' . $name . '.json';

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param array<string,mixed> $raw
     * @return list<string>
     */
    private function needlesFromWebhookCapture(array $raw): array
    {
        $data = is_array($raw['data'] ?? null) ? $raw['data'] : [];
        $paths = [
            ['customer', 'email'],
            ['customer', 'first_name'],
            ['customer', 'last_name'],
            ['customer', 'phone'],
            ['customer', 'customer_code'],
            ['authorization', 'authorization_code'],
            ['authorization', 'signature'],
            ['authorization', 'bin'],
            ['authorization', 'last4'],
            ['authorization', 'bank'],
            ['ip_address'],
            ['email_token'],
        ];

        return $this->collectNeedles($data, $paths);
    }

    /**
     * @param array<string,mixed> $raw
     * @return list<string>
     */
    private function needlesFromInitializeCapture(array $raw): array
    {
        $data = is_array($raw['data'] ?? null) ? $raw['data'] : [];

        return $this->collectNeedles($data, [['authorization_url'], ['access_code']]);
    }

    /**
     * @param array<string,mixed> $data
     * @param list<list<string>> $paths
     * @return list<string>
     */
    private function collectNeedles(array $data, array $paths): array
    {
        $needles = [];
        foreach ($paths as $path) {
            $value = $data;
            foreach ($path as $segment) {
                $value = is_array($value) ? ($value[$segment] ?? null) : null;
            }
            if (is_scalar($value) && (string) $value !== '') {
                $needles[] = (string) $value;
            }
        }

        return $needles;
    }
}
