<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Support\CheckoutSandboxProof;

/**
 * Pure fail-closed gate for {@see \Glueful\Extensions\Payvia\Console\CheckoutSandboxProofCommand}.
 *
 * This class takes ALREADY-RESOLVED facts (a config string, a secret string, and the outcome of
 * an ingestion-path probe the command performed) and decides, with no I/O of its own, whether the
 * live sandbox proof is allowed to proceed. That split is deliberate: everything that touches a
 * container, the database, or the network lives in the command (and in {@see IngestionPathProbe}
 * for the ingestion check specifically); this class is plain PHP so every failure mode is testable
 * with constructor arguments alone.
 *
 * Three independent checks, ALL of which must pass:
 *  1. The configured public Paystack webhook URL's path is exactly `/payvia/webhooks/paystack`
 *     (see `routes.php` — that's the one unauthenticated, signature-verified webhook endpoint).
 *  2. A Paystack webhook signature secret is present (whatever verifies `x-paystack-signature`).
 *  3. The provider_events ingestion path the webhook writes into is reachable right now.
 *
 * A missing/malformed webhook URL is treated exactly like an absent one — this command runs a
 * REAL sandbox proof; running it without confidence that the maintainer's dashboard actually
 * points at this Glueful install would silently strand the resulting checkout with no listener.
 */
final class SandboxProofPreflight
{
    private const REQUIRED_WEBHOOK_PATH = '/payvia/webhooks/paystack';

    public function __construct(
        private readonly ?string $webhookUrl,
        private readonly ?string $webhookSecret,
        private readonly bool $ingestionPathReachable,
        private readonly string $ingestionProbeDetail,
    ) {
    }

    /**
     * @return list<string> Empty when the preflight passes.
     */
    public function failures(): array
    {
        $failures = [];

        if (!$this->webhookUrlTargetsPaystackWebhook()) {
            $failures[] = sprintf(
                'Configured Paystack webhook URL (%s) does not target %s. Set '
                    . 'PAYVIA_PAYSTACK_WEBHOOK_URL to the exact URL configured on the Paystack '
                    . 'dashboard before running this command.',
                $this->displayedWebhookUrl(),
                self::REQUIRED_WEBHOOK_PATH
            );
        }

        if ($this->webhookSecret === null || trim($this->webhookSecret) === '') {
            $failures[] = 'Paystack webhook signature secret is missing '
                . '(PAYVIA_PAYSTACK_WEBHOOK_SECRET / PAYVIA_PAYSTACK_SECRET_KEY / PAYSTACK_SECRET_KEY).';
        }

        if (!$this->ingestionPathReachable) {
            $failures[] = 'Provider-event ingestion path is not reachable: ' . $this->ingestionProbeDetail;
        }

        return $failures;
    }

    public function passes(): bool
    {
        return $this->failures() === [];
    }

    private function displayedWebhookUrl(): string
    {
        return $this->webhookUrl !== null && trim($this->webhookUrl) !== '' ? $this->webhookUrl : '(not set)';
    }

    /**
     * Compares ONLY the URL's path component against the exact route Payvia registers for
     * Paystack in `routes.php` (`/payvia/webhooks/{gateway}` with `{gateway}` = `paystack`).
     * Scheme/host/query are irrelevant here (they vary per environment); a trailing slash is
     * tolerated, a wrong path (or a missing/blank URL entirely) is not.
     */
    private function webhookUrlTargetsPaystackWebhook(): bool
    {
        if ($this->webhookUrl === null || trim($this->webhookUrl) === '') {
            return false;
        }

        $path = parse_url(trim($this->webhookUrl), PHP_URL_PATH);

        return is_string($path) && rtrim($path, '/') === self::REQUIRED_WEBHOOK_PATH;
    }
}
