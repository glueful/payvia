<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Unit\Console;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Extensions\Payvia\Console\CheckoutSandboxProofCommand;
use Glueful\Extensions\Payvia\Contracts\ProviderEventRepositoryInterface;
use Glueful\Extensions\Payvia\Support\CheckoutSandboxProof\FixtureProjector;
use Glueful\Extensions\Payvia\Support\CheckoutSandboxProof\SandboxProofPreflight;
use Glueful\Extensions\Payvia\Tests\Support\FakeProviderEventRepository;
use Glueful\Queue\QueueManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckoutSandboxProofCommandTest extends TestCase
{
    // -----------------------------------------------------------------------------------------
    // Command wiring
    // -----------------------------------------------------------------------------------------

    public function testCommandIsRegisteredUnderTheExpectedNameAndDescription(): void
    {
        $command = $this->makeCommand(webhookUrl: null, webhookSecret: null, bindEvents: false);

        self::assertSame('payvia:checkout:sandbox-proof', $command->getName());
        self::assertStringContainsString('Paystack', (string) $command->getDescription());
        self::assertStringContainsString('sandbox', (string) $command->getDescription());
    }

    public function testCommandDeclaresItsExpectedOptions(): void
    {
        $command = $this->makeCommand(webhookUrl: null, webhookSecret: null, bindEvents: false);
        $definition = $command->getDefinition();

        foreach (['poll-seconds', 'poll-interval', 'plan-amount', 'plan-interval', 'currency', 'email'] as $option) {
            self::assertTrue($definition->hasOption($option), "Missing --{$option} option");
        }
    }

    // -----------------------------------------------------------------------------------------
    // Preflight — each failure mode fails closed, and none of them ever touch HTTP (no
    // HttpClient binding exists in any of these containers; a code path that reached for one
    // would blow up with an "Unknown service" exception instead of a clean FAILURE exit).
    // -----------------------------------------------------------------------------------------

    public function testFailsClosedWhenWebhookUrlIsNotConfigured(): void
    {
        $tester = $this->executeCommand(webhookUrl: null, webhookSecret: 'whsec_123');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('does not target /payvia/webhooks/paystack', $tester->getDisplay());
    }

    public function testFailsClosedWhenWebhookUrlTargetsTheWrongPath(): void
    {
        $tester = $this->executeCommand(
            webhookUrl: 'https://app.example.test/webhooks/other-gateway',
            webhookSecret: 'whsec_123'
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('does not target /payvia/webhooks/paystack', $tester->getDisplay());
    }

    public function testFailsClosedWhenWebhookSecretIsMissing(): void
    {
        $tester = $this->executeCommand(
            webhookUrl: 'https://app.example.test/payvia/webhooks/paystack',
            webhookSecret: null
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('signature secret is missing', $tester->getDisplay());
    }

    public function testFailsClosedWhenProviderEventRepositoryIsNotBound(): void
    {
        $tester = $this->executeCommand(
            webhookUrl: 'https://app.example.test/payvia/webhooks/paystack',
            webhookSecret: 'whsec_123',
            bindEvents: false
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('ProviderEventRepositoryInterface is not bound', $tester->getDisplay());
    }

    public function testFailsClosedWhenQueueEnabledWithoutABoundQueueManager(): void
    {
        $tester = $this->executeCommand(
            webhookUrl: 'https://app.example.test/payvia/webhooks/paystack',
            webhookSecret: 'whsec_123',
            bindEvents: true,
            queueEnabled: true,
            bindQueueManager: false
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('no QueueManager is bound', $tester->getDisplay());
    }

    public function testFailsClosedWhenProviderEventsTableIsUnreachable(): void
    {
        $tester = $this->executeCommand(
            webhookUrl: 'https://app.example.test/payvia/webhooks/paystack',
            webhookSecret: 'whsec_123',
            bindEvents: true,
            eventsThrows: true
        );

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('provider_events is not reachable', $tester->getDisplay());
    }

    public function testPreflightPassesWhenEveryCheckIsSatisfied(): void
    {
        // Exercised directly against the pure class (not through the command, which would then
        // proceed to make a real HTTP call) -- proves the "everything is fine" boolean logic
        // without needing live HTTP.
        $preflight = new SandboxProofPreflight(
            webhookUrl: 'https://app.example.test/payvia/webhooks/paystack/',
            webhookSecret: 'whsec_123',
            ingestionPathReachable: true,
            ingestionProbeDetail: 'ok'
        );

        self::assertTrue($preflight->passes());
        self::assertSame([], $preflight->failures());
    }

    // -----------------------------------------------------------------------------------------
    // FixtureProjector — closed allowlist, hostile payload
    // -----------------------------------------------------------------------------------------

    public function testProjectorExtractsOnlyAllowlistedFieldsFromAChargeSuccessPayload(): void
    {
        $projected = FixtureProjector::project($this->hostileChargeSuccessPayload());

        self::assertSame(
            [
                'event' => 'charge.success',
                'reference' => 'sbxproof_20260803_amt',
                'status' => 'success',
                'metadata' => ['origination_uuid' => 'sbxproof_orig_abc123'],
                'subscription_code' => 'SUB_legit_code',
                'amount' => ['amount' => 5000, 'currency' => 'NGN'],
            ],
            $projected
        );
    }

    public function testProjectorExtractsOnlyAllowlistedFieldsFromASubscriptionCreatePayload(): void
    {
        $projected = FixtureProjector::project($this->hostileSubscriptionCreatePayload());

        self::assertSame(
            [
                'event' => 'subscription.create',
                'status' => 'active',
                'subscription_code' => 'SUB_legit_code',
                'plan_code' => 'PLN_legit_code',
                'amount' => ['amount' => 5000, 'currency' => 'NGN'],
            ],
            $projected
        );
    }

    /**
     * The hostile-payload proof: stuff secrets/PII at every level a real Paystack payload could
     * plausibly carry them (customer, authorization, headers, nested smuggling under an allowed
     * key name) and assert NONE of those literal strings survive projection, in addition to the
     * exact-shape assertions above.
     */
    public function testHostilePayloadLeaksNothingBeyondTheAllowlist(): void
    {
        $projected = FixtureProjector::project($this->hostileChargeSuccessPayload());
        $encoded = (string) json_encode($projected);

        foreach ($this->forbiddenNeedles() as $needle) {
            self::assertStringNotContainsString($needle, $encoded, "Leaked forbidden value: {$needle}");
        }

        self::assertArrayNotHasKey('customer', $projected);
        self::assertArrayNotHasKey('authorization', $projected);
        self::assertArrayNotHasKey('headers', $projected);
        self::assertArrayNotHasKey('access_code', $projected);
        self::assertArrayNotHasKey('signature', $projected);
        self::assertArrayNotHasKey('log', $projected);
        self::assertArrayNotHasKey('fees', $projected);
    }

    public function testProjectorDropsNonScalarValuesSmuggledUnderAnAllowlistedKeyName(): void
    {
        $projected = FixtureProjector::project([
            'event' => 'charge.success',
            'data' => [
                'reference' => ['nested' => 'not-a-string'],
                'status' => ['also' => 'not-a-string'],
                'metadata' => ['origination_uuid' => ['nested' => 'evil']],
                'subscription_code' => ['nested' => 'evil'],
                'amount' => '5000', // string, not int -- must be treated as absent
                'currency' => 'NGN',
            ],
        ]);

        self::assertSame(['event' => 'charge.success'], $projected);
    }

    public function testProjectorReturnsOnlyEventWhenNothingElseIsPresent(): void
    {
        $projected = FixtureProjector::project([
            'event' => 'charge.success',
            'data' => [
                'customer' => ['email' => 'someone@example.com'],
            ],
        ]);

        self::assertSame(['event' => 'charge.success'], $projected);
    }

    public function testProjectorPrefersTopLevelSubscriptionCodeOverNestedOne(): void
    {
        $projected = FixtureProjector::project([
            'event' => 'subscription.create',
            'data' => [
                'subscription_code' => 'SUB_top_level',
                'subscription' => ['subscription_code' => 'SUB_nested_should_not_win'],
            ],
        ]);

        self::assertSame('SUB_top_level', $projected['subscription_code']);
    }

    // -----------------------------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function hostileChargeSuccessPayload(): array
    {
        return [
            'event' => 'charge.success',
            'data' => [
                'id' => 999888777,
                'domain' => 'test',
                'status' => 'success',
                'reference' => 'sbxproof_20260803_amt',
                'amount' => 5000,
                'currency' => 'NGN',
                'message' => null,
                'gateway_response' => 'Successful',
                'paid_at' => '2026-08-03T10:00:00.000Z',
                'created_at' => '2026-08-03T09:59:00.000Z',
                'channel' => 'card',
                'ip_address' => '203.0.113.7',
                'metadata' => [
                    'origination_uuid' => 'sbxproof_orig_abc123',
                    'evil_secret' => 'do-not-leak-this-secret',
                    'nested' => ['password' => 'do-not-leak-this-password'],
                ],
                'log' => ['history' => [['message' => 'do-not-leak-log-message']]],
                'fees' => 100,
                'fees_breakdown' => null,
                'subscription' => ['subscription_code' => 'SUB_legit_code'],
                'authorization' => [
                    'authorization_code' => 'AUTH_do_not_leak',
                    'bin' => '408408',
                    'last4' => '4081',
                    'signature' => 'SIG_do_not_leak',
                    'reusable' => true,
                ],
                'customer' => [
                    'email' => 'victim@example.com',
                    'first_name' => 'Victim',
                    'last_name' => 'Person',
                    'phone' => '+10000000000',
                    'customer_code' => 'CUS_do_not_leak',
                ],
                'plan' => [],
            ],
            'headers' => [
                'x-paystack-signature' => 'do-not-leak-this-header-signature',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function hostileSubscriptionCreatePayload(): array
    {
        return [
            'event' => 'subscription.create',
            'data' => [
                'domain' => 'test',
                'status' => 'active',
                'subscription_code' => 'SUB_legit_code',
                'email_token' => 'do-not-leak-email-token',
                'amount' => 5000,
                'currency' => 'NGN',
                'cron_expression' => '0 0 28 * *',
                'next_payment_date' => '2026-09-03T00:00:00.000Z',
                'createdAt' => '2026-08-03T10:00:05.000Z',
                'plan' => [
                    'id' => 1,
                    'name' => 'Payvia sandbox proof',
                    'plan_code' => 'PLN_legit_code',
                    'description' => null,
                    'amount' => 5000,
                    'interval' => 'monthly',
                    'currency' => 'NGN',
                ],
                'authorization' => [
                    'authorization_code' => 'AUTH_do_not_leak',
                    'signature' => 'SIG_do_not_leak',
                ],
                'customer' => [
                    'email' => 'victim@example.com',
                    'first_name' => 'Victim',
                    'phone' => '+10000000000',
                ],
            ],
        ];
    }

    /** @return list<string> */
    private function forbiddenNeedles(): array
    {
        return [
            'victim@example.com',
            'Victim',
            'Person',
            '+10000000000',
            'CUS_do_not_leak',
            'AUTH_do_not_leak',
            '408408',
            '4081',
            'SIG_do_not_leak',
            'do-not-leak-this-secret',
            'do-not-leak-this-password',
            'do-not-leak-log-message',
            'do-not-leak-this-header-signature',
        ];
    }

    // -----------------------------------------------------------------------------------------
    // Harness
    // -----------------------------------------------------------------------------------------

    private function executeCommand(
        ?string $webhookUrl,
        ?string $webhookSecret,
        bool $bindEvents = true,
        bool $queueEnabled = false,
        bool $bindQueueManager = true,
        bool $eventsThrows = false
    ): CommandTester {
        $command = $this->makeCommand(
            $webhookUrl,
            $webhookSecret,
            $bindEvents,
            $queueEnabled,
            $bindQueueManager,
            $eventsThrows
        );
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }

    private function makeCommand(
        ?string $webhookUrl,
        ?string $webhookSecret,
        bool $bindEvents = true,
        bool $queueEnabled = false,
        bool $bindQueueManager = true,
        bool $eventsThrows = false
    ): CheckoutSandboxProofCommand {
        $base = sys_get_temp_dir() . '/payvia-sandbox-proof-' . uniqid('', true);
        @mkdir($base . '/config', 0777, true);
        file_put_contents($base . '/config/payvia.php', "<?php\nreturn " . var_export([
            'gateways' => [
                'paystack' => [
                    'secret_key' => 'sk_test_dummy',
                    'webhook_secret' => $webhookSecret,
                    'webhook_url' => $webhookUrl,
                    'base_url' => 'https://api.paystack.co',
                ],
            ],
            'webhooks' => [
                'queue' => $queueEnabled,
            ],
        ], true) . ";\n");

        $context = new ApplicationContext($base, 'testing');
        $context->setConfigLoader(new ConfigurationLoader($base, 'testing', $base . '/config'));

        $bindings = [];
        if ($bindEvents) {
            $bindings[ProviderEventRepositoryInterface::class] = new FakeProviderEventRepository($eventsThrows);
        }
        if ($bindQueueManager) {
            $bindings[QueueManager::class] = $this->createStub(QueueManager::class);
        }

        $container = new class ($bindings) implements ContainerInterface {
            /** @param array<string,mixed> $bindings */
            public function __construct(private array $bindings)
            {
            }

            public function get(string $id): mixed
            {
                if (!array_key_exists($id, $this->bindings)) {
                    throw new \RuntimeException("Unknown service: {$id}");
                }

                return $this->bindings[$id];
            }

            public function has(string $id): bool
            {
                return array_key_exists($id, $this->bindings);
            }
        };
        $context->setContainer($container);

        return new CheckoutSandboxProofCommand($container, $context);
    }
}
