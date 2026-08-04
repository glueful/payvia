<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Console;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\BaseCommand;
use Glueful\Database\Connection;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Support\CheckoutSandboxProof\FixtureProjector;
use Glueful\Extensions\Payvia\Support\CheckoutSandboxProof\IngestionPathProbe;
use Glueful\Extensions\Payvia\Support\CheckoutSandboxProof\SandboxProofPreflight;
use Glueful\Extensions\Payvia\Support\PayviaSettings;
use Glueful\Helpers\Utils;
use Glueful\Http\Client as HttpClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * MAINTAINER-RUN gate for the workspace self-serve checkout program's Phase A. This command is
 * never run in CI: it makes REAL calls against a Paystack SANDBOX account, requires a human to
 * complete one hosted checkout, and its output (the fixtures in `tests/Fixtures/paystack-checkout/`)
 * is what unblocks Task 3. See that directory's README for prerequisites, timeout/cleanup
 * behavior, and the §3.1 decision procedure the maintainer applies to the printed/captured
 * event shapes.
 *
 * Flow (fails closed at the first step -- nothing after preflight ever runs if it doesn't pass):
 *  1. Preflight: configured Paystack webhook URL targets `/payvia/webhooks/paystack`, a webhook
 *     signature secret is present, and the provider_events ingestion path is reachable.
 *  2. Record a start timestamp (UTC) and an exact reference; create a throwaway Paystack plan.
 *  3. `POST /transaction/initialize` twice against that plan -- once WITHOUT `amount`, once WITH
 *     -- recording both raw responses (printed for the maintainer, never written as fixtures).
 *  4. Print the checkout URL and wait/poll instructions.
 *  5. Poll `provider_events` for post-start `charge.success`/`subscription.create` rows that
 *     correlate to this run (by reference, by our generated `origination_uuid`, or by this run's
 *     throwaway plan code).
 *  6. Write each matched row's raw payload through {@see FixtureProjector}'s closed allowlist.
 */
#[AsCommand(
    name: 'payvia:checkout:sandbox-proof',
    description: 'Prove Paystack subscription checkout against a real sandbox and capture allowlisted fixtures'
)]
final class CheckoutSandboxProofCommand extends BaseCommand
{
    private const FIXTURE_DIR = __DIR__ . '/../../tests/Fixtures/paystack-checkout';

    protected function configure(): void
    {
        $this->addOption(
            'poll-seconds',
            null,
            InputOption::VALUE_OPTIONAL,
            'Total seconds to poll provider_events for matching post-start rows',
            600
        );
        $this->addOption(
            'poll-interval',
            null,
            InputOption::VALUE_OPTIONAL,
            'Seconds to sleep between polls',
            5
        );
        $this->addOption(
            'plan-amount',
            null,
            InputOption::VALUE_OPTIONAL,
            'Minor-unit amount for the throwaway plan/transaction',
            5000
        );
        $this->addOption(
            'plan-interval',
            null,
            InputOption::VALUE_OPTIONAL,
            'Paystack plan billing interval',
            'monthly'
        );
        $this->addOption(
            'currency',
            null,
            InputOption::VALUE_OPTIONAL,
            'Currency for the throwaway plan/transaction',
            'NGN'
        );
        $this->addOption(
            'email',
            null,
            InputOption::VALUE_OPTIONAL,
            'Customer email sent to transaction/initialize',
            'payvia-sandbox-proof@example.test'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->getContext();

        [$reachable, $probeDetail] = (new IngestionPathProbe($context))->probe();
        $config = PayviaSettings::gatewayConfig($context, 'paystack');
        $webhookUrl = isset($config['webhook_url']) && is_string($config['webhook_url'])
            ? $config['webhook_url']
            : null;
        $webhookSecret = isset($config['webhook_secret']) && is_string($config['webhook_secret'])
            ? $config['webhook_secret']
            : null;

        $preflight = new SandboxProofPreflight($webhookUrl, $webhookSecret, $reachable, $probeDetail);
        if (!$preflight->passes()) {
            $this->error('Preflight FAILED. The Paystack sandbox proof will NOT run:');
            foreach ($preflight->failures() as $failure) {
                $this->line(' - ' . $failure);
            }

            return self::FAILURE;
        }
        $this->info('Preflight passed: webhook URL, signature secret, and ingestion path all verified.');

        $secretKey = (string) ($config['secret_key'] ?? '');
        if (trim($secretKey) === '') {
            $this->error('Missing Paystack secret key '
                . '(PAYVIA_PAYSTACK_SECRET_KEY / PAYSTACK_SECRET_KEY).');

            return self::FAILURE;
        }
        $baseUrl = rtrim((string) ($config['base_url'] ?? 'https://api.paystack.co'), '/');
        $timeout = (int) ($config['timeout'] ?? 15);

        $startedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $reference = 'sbxproof_' . $startedAt->format('YmdHis') . '_' . Utils::generateNanoID(8);
        $referenceWithAmount = $reference . '_amt';
        $originationUuid = 'sbxproof_orig_' . Utils::generateNanoID(16);

        $this->info('Start timestamp (UTC): ' . $startedAt->format(DATE_ATOM));
        $this->info("Exact reference (without amount): {$reference}");
        $this->info("Exact reference (with amount): {$referenceWithAmount}");
        $this->info("Correlation origination_uuid: {$originationUuid}");

        $http = $this->httpClient();

        $currency = (string) $input->getOption('currency');
        $planAmount = (int) $input->getOption('plan-amount');
        $plan = $this->createThrowawayPlan(
            $http,
            $baseUrl,
            $secretKey,
            $timeout,
            $planAmount,
            (string) $input->getOption('plan-interval'),
            $currency,
            $startedAt
        );
        if ($plan === null) {
            $this->error('Failed to create a throwaway Paystack plan; aborting.');

            return self::FAILURE;
        }
        $planCode = $plan;
        $this->info("Created throwaway plan {$planCode}.");

        $email = (string) $input->getOption('email');
        $withoutAmount = $this->initializeTransaction(
            $http,
            $baseUrl,
            $secretKey,
            $timeout,
            $reference,
            $email,
            $planCode,
            null,
            $currency,
            $originationUuid
        );
        $withAmount = $this->initializeTransaction(
            $http,
            $baseUrl,
            $secretKey,
            $timeout,
            $referenceWithAmount,
            $email,
            $planCode,
            $planAmount,
            $currency,
            $originationUuid
        );

        $this->line('');
        $this->line('<comment>Raw /transaction/initialize response WITHOUT amount:</comment>');
        $this->line((string) json_encode($withoutAmount, JSON_PRETTY_PRINT));
        $this->line('');
        $this->line('<comment>Raw /transaction/initialize response WITH amount:</comment>');
        $this->line((string) json_encode($withAmount, JSON_PRETTY_PRINT));

        $checkoutUrl = $this->authorizationUrl($withAmount) ?? $this->authorizationUrl($withoutAmount);
        if ($checkoutUrl === null) {
            $this->error('Neither transaction/initialize call returned a checkout URL; aborting.');

            return self::FAILURE;
        }

        $this->line('');
        $this->info("Checkout URL: {$checkoutUrl}");
        $this->line('Open that URL and complete ONE hosted checkout now, using whichever reference');
        $this->line('the working initialize call above used (' . $reference . ' or ' . $referenceWithAmount . ').');
        $this->line('Waiting for post-start charge.success / subscription.create webhook rows...');

        $matched = $this->pollForMatches(
            $context,
            $startedAt,
            $reference,
            $referenceWithAmount,
            $originationUuid,
            $planCode,
            (int) $input->getOption('poll-seconds'),
            max(1, (int) $input->getOption('poll-interval'))
        );

        if ($matched === []) {
            $this->error(
                'Timed out waiting for a matching charge.success/subscription.create row. '
                . 'Paystack initiation stays UNAVAILABLE for this run; per the README\'s §3.1 '
                . 'decision procedure, the Phase A release gate CANNOT pass on this attempt.'
            );

            return self::FAILURE;
        }

        $written = $this->writeFixtures($matched);
        $this->info('Wrote ' . count($written) . ' fixture(s) to ' . self::FIXTURE_DIR);
        foreach ($written as $path) {
            $this->line(' - ' . $path);
        }
        $this->line('');
        $this->line('Apply the README\'s §3.1 decision procedure to the fixtures above and record');
        $this->line('which mode (and amount shape) they prove in the fixtures commit message.');

        return self::SUCCESS;
    }

    private function httpClient(): HttpClient
    {
        $context = $this->getContext();
        $container = $context->getContainer();

        /** @var HttpClient $client */
        $client = $container->get(HttpClient::class);

        return $client;
    }

    /**
     * @return string|null The created plan's `plan_code`, or null on failure.
     */
    private function createThrowawayPlan(
        HttpClient $http,
        string $baseUrl,
        string $secretKey,
        int $timeout,
        int $amount,
        string $interval,
        string $currency,
        \DateTimeImmutable $startedAt
    ): ?string {
        $response = $http->post($baseUrl . '/plan', [
            'headers' => [
                'Authorization' => 'Bearer ' . $secretKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'name' => 'Payvia sandbox proof ' . $startedAt->format('Ymd\THis\Z'),
                'amount' => $amount,
                'interval' => $interval,
                'currency' => $currency,
            ],
            'timeout' => $timeout,
        ]);

        $decoded = $response->toArray();
        if (!(bool) ($decoded['status'] ?? false)) {
            return null;
        }

        $data = (array) ($decoded['data'] ?? []);
        $planCode = $data['plan_code'] ?? null;

        return is_string($planCode) && $planCode !== '' ? $planCode : null;
    }

    /**
     * @return array<string,mixed> The decoded raw response.
     */
    private function initializeTransaction(
        HttpClient $http,
        string $baseUrl,
        string $secretKey,
        int $timeout,
        string $reference,
        string $email,
        string $planCode,
        ?int $amount,
        string $currency,
        string $originationUuid
    ): array {
        $json = [
            'email' => $email,
            'reference' => $reference,
            'plan' => $planCode,
            'currency' => $currency,
            'metadata' => [
                'origination_uuid' => $originationUuid,
                'purpose' => 'payvia_checkout_sandbox_proof',
            ],
        ];
        if ($amount !== null) {
            $json['amount'] = $amount;
        }

        $response = $http->post($baseUrl . '/transaction/initialize', [
            'headers' => [
                'Authorization' => 'Bearer ' . $secretKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => $json,
            'timeout' => $timeout,
        ]);

        return $response->toArray();
    }

    /** @param array<string,mixed> $decoded */
    private function authorizationUrl(array $decoded): ?string
    {
        $data = (array) ($decoded['data'] ?? []);
        $url = $data['authorization_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Polls `provider_events` directly (no repository method exists for "find by type/gateway
     * since a timestamp", so this reads through the shared connection the same way the
     * repository itself does) until either the poll window elapses or at least one matching row
     * has been found. A row "matches this run" when its raw payload correlates via ANY of:
     * the exact reference we sent, our generated `origination_uuid` in `data.metadata`, or (for
     * `subscription.create` specifically) this run's throwaway `plan_code` -- the plan is
     * single-use, so that alone is a safe correlation key regardless of whether Paystack
     * propagated our metadata into the subscription event.
     *
     * @return list<array{type:string,raw:array<string,mixed>}>
     */
    private function pollForMatches(
        ApplicationContext $context,
        \DateTimeImmutable $startedAt,
        string $reference,
        string $referenceWithAmount,
        string $originationUuid,
        string $planCode,
        int $pollSeconds,
        int $pollInterval
    ): array {
        $container = $context->getContainer();
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $cutoff = $connection->getDriver()->formatDateTime($startedAt->format('Y-m-d H:i:s'));

        $deadline = time() + max(0, $pollSeconds);
        $matches = [];
        $seenUuids = [];

        do {
            $rows = $connection->table('provider_events')
                ->where('gateway', '=', 'paystack')
                ->where('signature_valid', '=', true)
                ->whereIn('type', [EventType::PAYMENT_SUCCEEDED, EventType::SUBSCRIPTION_CREATED])
                ->where('received_at', '>=', $cutoff)
                ->orderBy(['received_at' => 'ASC'])
                ->get();

            foreach ($rows as $row) {
                $uuid = (string) ($row['uuid'] ?? '');
                if ($uuid === '' || isset($seenUuids[$uuid])) {
                    continue;
                }

                $raw = $this->decodeRawPayload($row);
                if ($raw === null) {
                    continue;
                }

                if (!$this->rowMatchesRun($row, $raw, $reference, $referenceWithAmount, $originationUuid, $planCode)) {
                    continue;
                }

                $seenUuids[$uuid] = true;
                $matches[] = ['type' => (string) ($row['type'] ?? ''), 'raw' => $raw];
            }

            if ($matches !== [] || time() >= $deadline) {
                break;
            }

            sleep($pollInterval);
        } while (true);

        return $matches;
    }

    /** @param array<string,mixed> $row @return array<string,mixed>|null */
    private function decodeRawPayload(array $row): ?array
    {
        $raw = $row['raw_payload'] ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $raw */
    private function rowMatchesRun(
        array $row,
        array $raw,
        string $reference,
        string $referenceWithAmount,
        string $originationUuid,
        string $planCode
    ): bool {
        $data = is_array($raw['data'] ?? null) ? $raw['data'] : [];

        $rowReference = $data['reference'] ?? null;
        if (is_string($rowReference) && ($rowReference === $reference || $rowReference === $referenceWithAmount)) {
            return true;
        }

        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        if (($metadata['origination_uuid'] ?? null) === $originationUuid) {
            return true;
        }

        if ((string) ($row['type'] ?? '') === EventType::SUBSCRIPTION_CREATED) {
            $plan = is_array($data['plan'] ?? null) ? $data['plan'] : [];
            if (($plan['plan_code'] ?? null) === $planCode) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{type:string,raw:array<string,mixed>}> $matched
     * @return list<string> Written file paths.
     */
    private function writeFixtures(array $matched): array
    {
        if (!is_dir(self::FIXTURE_DIR)) {
            mkdir(self::FIXTURE_DIR, 0755, true);
        }

        $written = [];
        foreach ($matched as $index => $entry) {
            $projected = FixtureProjector::project($entry['raw']);
            $slug = str_replace('.', '-', $entry['type']);
            $path = self::FIXTURE_DIR . '/' . $slug . '-' . ($index + 1) . '.json';
            file_put_contents(
                $path,
                (string) json_encode($projected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
            );
            $written[] = $path;
        }

        return $written;
    }
}
