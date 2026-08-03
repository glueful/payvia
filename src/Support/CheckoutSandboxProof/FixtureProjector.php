<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Support\CheckoutSandboxProof;

/**
 * The security-critical piece of the sandbox-proof harness: turns a raw Paystack event payload
 * (`{event, data, ...}`) into the ONLY shape ever allowed to reach a committed fixture file.
 *
 * This is a CLOSED ALLOWLIST projector, not denylist scrubbing. Every key in the returned array
 * is written by reading one EXACT, named path out of `$rawPayload` and copying it, ONLY when it
 * is a scalar (never an array/object smuggled in under an allowed key name). There is no
 * "copy everything except X" step anywhere in this class -- a field is either named below, or it
 * is structurally impossible for it to appear in the return value, no matter what the raw payload
 * contains or how deeply nested it is. That is what makes the hostile-payload test meaningful: it
 * cannot pass by accident, only by every private helper here staying this narrow.
 *
 * Allowed output keys (all optional -- absent/non-scalar input drops the key entirely):
 *  - `event`             <- top-level `event`
 *  - `reference`         <- `data.reference`
 *  - `status`             <- `data.status`
 *  - `metadata.origination_uuid` <- `data.metadata.origination_uuid` (the ONLY metadata key ever
 *                          copied -- every other metadata field, however named, is dropped)
 *  - `subscription_code` <- `data.subscription_code` OR `data.subscription.subscription_code`
 *                          (the two locations Paystack's `subscription.create` / a `charge.success`
 *                          carrying nested subscription context use -- see the task README's
 *                          §3.1 decision procedure)
 *  - `plan_code`         <- `data.plan_code` OR `data.plan.plan_code`
 *  - `amount`            <- `{amount: data.amount, currency: data.currency}`, the minimum amount
 *                          shape (both integer-minor-unit `amount` and `currency` present, or
 *                          neither) -- no `fees`, `gateway_response`, or other amount-adjacent
 *                          field is ever copied.
 *
 * Never reachable, by construction (no code path reads them): customer objects, names, emails,
 * phones, addresses, `authorization`/`access_code`/`signature` values, transport headers, and any
 * other raw field -- including ones a hostile/forged payload adds under an otherwise-allowed key
 * name (e.g. `data.reference` set to an array instead of a string is dropped, not smuggled through
 * as a serialized array).
 */
final class FixtureProjector
{
    /**
     * @param array<string,mixed> $rawPayload
     * @return array<string,mixed>
     */
    public static function project(array $rawPayload): array
    {
        $data = is_array($rawPayload['data'] ?? null) ? $rawPayload['data'] : [];

        $fixture = [
            'event' => self::scalarString($rawPayload['event'] ?? null),
            'reference' => self::scalarString($data['reference'] ?? null),
            'status' => self::scalarString($data['status'] ?? null),
            'metadata' => self::projectMetadata($data),
            'subscription_code' => self::projectSubscriptionCode($data),
            'plan_code' => self::projectPlanCode($data),
            'amount' => self::projectAmount($data),
        ];

        return array_filter($fixture, static fn(mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string,mixed> $data
     * @return array{origination_uuid:string}|null
     */
    private static function projectMetadata(array $data): ?array
    {
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $originationUuid = self::scalarString($metadata['origination_uuid'] ?? null);

        return $originationUuid !== null ? ['origination_uuid' => $originationUuid] : null;
    }

    /**
     * Only these two exact locations are ever consulted.
     *
     * @param array<string,mixed> $data
     */
    private static function projectSubscriptionCode(array $data): ?string
    {
        $direct = self::scalarString($data['subscription_code'] ?? null);
        if ($direct !== null) {
            return $direct;
        }

        $nested = is_array($data['subscription'] ?? null) ? $data['subscription'] : [];

        return self::scalarString($nested['subscription_code'] ?? null);
    }

    /**
     * Only these two exact locations are ever consulted.
     *
     * @param array<string,mixed> $data
     */
    private static function projectPlanCode(array $data): ?string
    {
        $direct = self::scalarString($data['plan_code'] ?? null);
        if ($direct !== null) {
            return $direct;
        }

        $plan = is_array($data['plan'] ?? null) ? $data['plan'] : [];

        return self::scalarString($plan['plan_code'] ?? null);
    }

    /**
     * Minor-unit integer `amount` only -- a float, numeric string, or anything else is treated
     * as absent rather than coerced, so a forged non-integer amount can never masquerade as a
     * trustworthy wire value. Both fields must be present or neither is written.
     *
     * @param array<string,mixed> $data
     * @return array{amount:int,currency:string}|null
     */
    private static function projectAmount(array $data): ?array
    {
        $amount = $data['amount'] ?? null;
        if (!is_int($amount)) {
            return null;
        }

        $currency = self::scalarString($data['currency'] ?? null);
        if ($currency === null) {
            return null;
        }

        return ['amount' => $amount, 'currency' => $currency];
    }

    private static function scalarString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
