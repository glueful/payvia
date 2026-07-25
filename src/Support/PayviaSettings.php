<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Support;

use Glueful\Bootstrap\ApplicationContext;

/**
 * The ONE read surface for runtime-editable payment settings. Each read consults a
 * host-bound {@see PayviaSettingsOverride} first, then falls back to the matching
 * `config/payvia.php` key — so with no binding every install behaves exactly as before,
 * and with one, hosts make these keys editable at runtime:
 *
 * - `payvia.default_gateway`
 * - `payvia.gateways.{id}.enabled`
 * - `payvia.gateways.{id}.secret_key`
 * - `payvia.gateways.{id}.webhook_secret`
 *
 * Everything else in `payvia.*` (base URLs, timeouts, middleware profiles, feature
 * flags) stays config/env-only on purpose — those are operator knobs, not store
 * settings. Casting is DEFENSIVE: an override that doesn't parse as the expected shape
 * (a gateway id that isn't a slug, an enabled flag that isn't boolean-ish) is treated
 * as no-override and falls through to config — a corrupted stored row must never leak
 * a malformed value into payment routing or signature verification.
 */
final class PayviaSettings
{
    public static function defaultGateway(ApplicationContext $context): string
    {
        $override = self::override($context, 'payvia.default_gateway');
        if ($override !== null && preg_match('/^[a-z0-9_-]+$/', trim($override)) === 1) {
            return trim($override);
        }

        return (string) config($context, 'payvia.default_gateway', 'paystack');
    }

    /**
     * One gateway's effective config: the `payvia.gateways.{id}` config array with the
     * overridable keys overlaid. Non-overridable keys (driver, base_url, timeout, …)
     * pass through untouched, as does Paystack's webhook_secret→secret_key fallback —
     * the overlay only replaces keys the override actually answers for.
     *
     * @return array<string,mixed>
     */
    public static function gatewayConfig(ApplicationContext $context, string $gateway): array
    {
        return self::overlay($context, $gateway, (array) config($context, 'payvia.gateways.' . $gateway, []));
    }

    /**
     * The full gateway map with overlays applied — GatewayManager's view. Gateway ids
     * come from config alone: an override can reconfigure a configured gateway but
     * never invent a new one.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function gateways(ApplicationContext $context): array
    {
        $out = [];
        foreach ((array) config($context, 'payvia.gateways', []) as $name => $config) {
            $out[(string) $name] = self::overlay($context, (string) $name, (array) $config);
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private static function overlay(ApplicationContext $context, string $gateway, array $config): array
    {
        $prefix = 'payvia.gateways.' . $gateway . '.';

        $enabled = self::override($context, $prefix . 'enabled');
        if ($enabled !== null) {
            $flag = strtolower(trim($enabled));
            if (in_array($flag, ['1', 'true', '0', 'false'], true)) {
                $config['enabled'] = in_array($flag, ['1', 'true'], true);
            }
        }

        foreach (['secret_key', 'webhook_secret'] as $key) {
            $value = self::override($context, $prefix . $key);
            if ($value !== null && trim($value) !== '') {
                $config[$key] = $value;
            }
        }

        return $config;
    }

    private static function override(ApplicationContext $context, string $key): ?string
    {
        // A context without a container (early boot, bare test harnesses) simply has
        // no override — config()/env is the answer, never an exception.
        if (!$context->hasContainer()) {
            return null;
        }

        $container = $context->getContainer();
        if (!$container->has(PayviaSettingsOverride::class)) {
            return null;
        }

        /** @var PayviaSettingsOverride $resolver */
        $resolver = $container->get(PayviaSettingsOverride::class);

        return $resolver->value($context, $key);
    }
}
