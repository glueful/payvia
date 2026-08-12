<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Support;

/**
 * Payvia's hosted-redirect trust boundary (payment-links spec §2.1).
 *
 * Whatever a provider hands back as "send the customer here" is attacker-adjacent data: it ends
 * up in an intent payload, is later handed to a browser as a redirect, and -- for the payment-link
 * flow -- is the ONE url a merchant tells a customer to trust. A compromised or merely
 * misconfigured provider response must therefore never be able to redirect a payer anywhere but
 * the driver's own checkout host.
 *
 * Matching is case-normalized (DNS is case-insensitive) but otherwise EXACT. Everything else is
 * refused, including the shapes that look benign:
 *
 *  - non-HTTPS (`http://`, protocol-relative, scheme-less) -- a payment page has no plaintext mode;
 *  - userinfo (`https://checkout.example.com@evil.test/`) -- the classic "host is what you read
 *    first" spoof; `parse_url()` correctly reports `evil.test` as the host, humans do not;
 *  - an explicit port, even `:443` -- a trusted host on an untrusted port is a different endpoint;
 *  - a trailing dot (`checkout.example.com.`) -- resolves identically in DNS but is not an exact
 *    string match, so allowing it would mean two spellings of the allow-list;
 *  - sub/superdomain lookalikes (`a.checkout.example.com`, `xcheckout.example.com`,
 *    `checkout.example.com.evil.test`) -- exact matching is what kills these;
 *  - whitespace or control characters anywhere in the url (header/redirect-splitting shapes);
 *  - anything that is not a parseable string at all.
 */
final class HostedCheckoutUrl
{
    /**
     * @param list<string> $allowedHosts already-normalized hosts (see {@see configuredHosts()})
     * @return string|null the url unchanged when trusted, null otherwise
     */
    public static function trusted(mixed $url, array $allowedHosts): ?string
    {
        if (!is_string($url) || $url === '') {
            return null;
        }

        // No whitespace/control characters anywhere -- not just at the edges.
        if (preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || str_ends_with($host, '.')) {
            return null;
        }

        return in_array($host, $allowedHosts, true) ? $url : null;
    }

    /**
     * The effective allow-list for a driver: `gateways.{id}.checkout_hosts` when configured,
     * otherwise the driver's own shipped default.
     *
     * A MISSING key falls back to the shipped default so an install carrying a pre-2.6.0
     * `config/payvia.php` keeps working (the alternative -- trusting nothing -- would break
     * checkout on upgrade). A malformed value is likewise treated as no answer, mirroring
     * {@see PayviaSettings}'s defensive-casting rule: a corrupted value must never WIDEN trust.
     * An explicitly empty array, however, is an operator decision and is honored: nothing is
     * trusted and every url is refused.
     *
     * @param array<string,mixed> $gatewayConfig
     * @param list<string> $shippedDefaults
     * @return list<string>
     */
    public static function configuredHosts(array $gatewayConfig, array $shippedDefaults): array
    {
        $configured = $gatewayConfig['checkout_hosts'] ?? null;
        if (!is_array($configured)) {
            return self::normalize($shippedDefaults);
        }

        return self::normalize($configured);
    }

    /**
     * @param array<array-key,mixed> $hosts
     * @return list<string>
     */
    private static function normalize(array $hosts): array
    {
        $out = [];
        foreach ($hosts as $host) {
            if (!is_string($host)) {
                continue;
            }
            $normalized = strtolower(trim($host));
            if ($normalized !== '' && !in_array($normalized, $out, true)) {
                $out[] = $normalized;
            }
        }

        return $out;
    }
}
