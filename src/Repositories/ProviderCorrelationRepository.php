<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Repositories;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Payvia\Repositories\Concerns\DetectsUniqueViolations;
use Glueful\Helpers\Utils;
use Glueful\Repository\BaseRepository;

/**
 * The ONLY tenantless table-access surface in Payvia.
 *
 * Provider webhooks and subscription reconciliation correlate by a globally unique provider
 * key -- (gateway, gateway_subscription_id) -- and billing-plan correlation by the globally
 * unique plan `uuid`, both before any request tenant is known. This repository is NOT
 * controller-visible: it supports only the exact provider-subscription and billing-plan UUID
 * correlation needed today, and every mutation predicate includes the located row's OWN
 * `tenant_uuid`, so correlation can never transfer ownership between tenants.
 *
 * When a host tenancy package is active, reads/writes run inside the neutral
 * `TenantContextRunner::runAsSystem()` seam: this deliberately suspends per-request row scoping
 * for this trusted, audited surface while preserving QueryExecutor interception, Thallo's
 * mutation barrier, and query observability. With no tenancy resolver bound (single-store),
 * `$runner` is legitimately null and every call executes the closure directly. A bound shared
 * `CurrentTenantResolver` with no runner supplied is a misconfiguration -- this fails closed at
 * construction time, not silently at the first query.
 */
final class ProviderCorrelationRepository extends BaseRepository
{
    use DetectsUniqueViolations;

    public function __construct(
        ?Connection $connection = null,
        ?ApplicationContext $context = null,
        bool $tenancyResolverPresent = false,
        private readonly ?TenantContextRunner $runner = null,
    ) {
        if ($tenancyResolverPresent && $this->runner === null) {
            throw new \RuntimeException(
                'ProviderCorrelationRepository requires a TenantContextRunner when a shared '
                . 'CurrentTenantResolver is bound (tenancy-enabled host); none was provided.'
            );
        }

        parent::__construct($connection, $context);
    }

    /**
     * Nominal: this repository spans two global-correlation tables (gateway_subscriptions,
     * billing_plans) and never reads `$this->table` -- every query names its table explicitly.
     */
    public function getTableName(): string
    {
        return 'gateway_subscriptions';
    }

    /** @return array<string,mixed>|null */
    public function findGatewaySubscriptionByGatewayId(string $gateway, string $gatewaySubscriptionId): ?array
    {
        if ($gateway === '' || $gatewaySubscriptionId === '') {
            return null;
        }

        /** @var array<string,mixed>|null $row */
        $row = $this->system(fn (): mixed => $this->db->table('gateway_subscriptions')
            ->where(['gateway' => $gateway, 'gateway_subscription_id' => $gatewaySubscriptionId])
            ->limit(1)
            ->first());

        return $row;
    }

    /**
     * Correlate a provider dispute/webhook payload to its persisted payment OWNER.
     *
     * Runs through the same tenantless `system()` seam as the subscription/billing-plan
     * correlations above -- a dispute webhook arrives with no request tenant bound, so this
     * cannot be an interactive tenant-scoped read. Fails closed on ambiguity: `gateway` +
     * `gateway_transaction_id` is not a persisted-unique key (unlike `reference`), so if the
     * bounded lookup returns anything other than exactly one row -- zero matches, or multiple
     * rows even when owned by different tenants -- this returns null rather than ever guessing
     * an owner.
     *
     * @return array{
     *     tenant_uuid: string,
     *     reference: string,
     *     payable_type: mixed,
     *     payable_id: mixed,
     *     amount: int,
     *     currency: string
     * }|null
     */
    public function findPaymentOwnerByGatewayTxn(string $gateway, string $gatewayTransactionId): ?array
    {
        if ($gateway === '' || $gatewayTransactionId === '') {
            return null;
        }

        /** @var list<array<string,mixed>> $rows */
        $rows = $this->system(fn (): mixed => $this->db->table('payments')
            ->where(['gateway' => $gateway, 'gateway_transaction_id' => $gatewayTransactionId])
            ->get());

        if (count($rows) !== 1) {
            return null;
        }

        $row = $rows[0];

        return [
            'tenant_uuid' => (string) $row['tenant_uuid'],
            'reference' => (string) $row['reference'],
            'payable_type' => $row['payable_type'],
            'payable_id' => $row['payable_id'],
            'amount' => (int) $row['amount'],
            'currency' => (string) $row['currency'],
        ];
    }

    /** @return array<string,mixed>|null */
    public function findBillingPlanByUuid(string $uuid): ?array
    {
        if ($uuid === '') {
            return null;
        }

        /** @var array<string,mixed>|null $row */
        $row = $this->system(fn (): mixed => $this->db->table('billing_plans')
            ->where(['uuid' => $uuid])
            ->limit(1)
            ->first());

        return $row;
    }

    /**
     * Find-or-create by the global (gateway, gateway_subscription_id) key.
     *
     * The insert path stamps whatever `tenant_uuid` the caller supplies (default '' -- the
     * column default, so single-store callers are unaffected). The update path is ALWAYS
     * owner-qualified against the already-persisted row's own `tenant_uuid`; any caller-supplied
     * `tenant_uuid` is discarded on that path, so a caller can never move a projection between
     * tenants merely by upserting.
     *
     * @param array<string,mixed> $data
     */
    public function upsertGatewaySubscription(array $data): string
    {
        $gateway = (string) $data['gateway'];
        $gatewaySubscriptionId = (string) $data['gateway_subscription_id'];
        $existing = $this->findGatewaySubscriptionByGatewayId($gateway, $gatewaySubscriptionId);
        $now = $this->db->getDriver()->formatDateTime();
        $payload = $this->normalizeJson($data);

        if ($existing === null) {
            $uuid = Utils::generateNanoID(12);
            $insertPayload = array_merge($payload, [
                'uuid' => $uuid,
                'tenant_uuid' => (string) ($payload['tenant_uuid'] ?? ''),
                'status' => $payload['status'] ?? 'active',
                'created_at' => $now,
            ]);

            try {
                $this->system(fn (): mixed => $this->db->table('gateway_subscriptions')->insert($insertPayload));

                return $uuid;
            } catch (\Throwable $e) {
                if (!$this->isUniqueViolation($e)) {
                    throw $e;
                }

                // Lost a concurrent race: the row was inserted between the find and our
                // insert. Re-fetch and fall through to the owner-qualified update path so the
                // data this caller carries is still applied.
                $existing = $this->findGatewaySubscriptionByGatewayId($gateway, $gatewaySubscriptionId);
                if ($existing === null) {
                    throw $e;
                }
            }
        }

        // Never let an upsert move ownership: drop any caller-supplied tenant_uuid and
        // constrain the update to the row's OWN persisted tenant.
        unset($payload['tenant_uuid']);
        $ownerTenant = (string) ($existing['tenant_uuid'] ?? '');
        $this->updateGatewaySubscriptionOwned($ownerTenant, (string) $existing['uuid'], array_merge($payload, [
            'updated_at' => $now,
        ]));

        return (string) $existing['uuid'];
    }

    /**
     * Owner-qualified mutation: the predicate always includes the located row's own
     * `tenant_uuid`, so this can never write across a tenant boundary -- a wrong tenant simply
     * matches zero rows.
     *
     * @param array<string,mixed> $data
     */
    public function updateGatewaySubscriptionOwned(string $tenantUuid, string $uuid, array $data): bool
    {
        if ($uuid === '') {
            return false;
        }

        $affected = (int) $this->system(fn (): mixed => $this->db->table('gateway_subscriptions')
            ->where(['uuid' => $uuid, 'tenant_uuid' => $tenantUuid])
            ->update($data));

        return $affected > 0;
    }

    private function system(callable $fn): mixed
    {
        return $this->runner?->runAsSystem($fn) ?? $fn();
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalizeJson(array $data): array
    {
        if (isset($data['raw_payload']) && is_array($data['raw_payload'])) {
            $data['raw_payload'] = json_encode($data['raw_payload'], JSON_THROW_ON_ERROR);
        }
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = json_encode($data['metadata'], JSON_THROW_ON_ERROR);
        }

        return $data;
    }
}
