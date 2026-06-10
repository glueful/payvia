<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Repositories;

use Glueful\Extensions\Payvia\Contracts\GatewaySubscriptionRepositoryInterface;
use Glueful\Helpers\Utils;
use Glueful\Repository\BaseRepository;

final class GatewaySubscriptionRepository extends BaseRepository implements GatewaySubscriptionRepositoryInterface
{
    public function getTableName(): string
    {
        return 'gateway_subscriptions';
    }

    public function findByGatewaySubscription(string $gateway, string $gatewaySubscriptionId): ?array
    {
        return $this->db->table($this->getTableName())
            ->where(['gateway' => $gateway, 'gateway_subscription_id' => $gatewaySubscriptionId])
            ->limit(1)
            ->first();
    }

    public function upsertByGatewayId(array $data): string
    {
        $gateway = (string) $data['gateway'];
        $gatewaySubscriptionId = (string) $data['gateway_subscription_id'];
        $existing = $this->findByGatewaySubscription($gateway, $gatewaySubscriptionId);
        $now = $this->db->getDriver()->formatDateTime();
        $payload = $this->normalizeJson($data);

        if ($existing === null) {
            $uuid = Utils::generateNanoID(12);
            $this->db->table($this->getTableName())->insert(array_merge($payload, [
                'uuid' => $uuid,
                'created_at' => $now,
            ]));
            return $uuid;
        }

        $this->db->table($this->getTableName())
            ->where(['uuid' => $existing['uuid']])
            ->update(array_merge($payload, ['updated_at' => $now]));

        return (string) $existing['uuid'];
    }

    /** @param array<string,mixed> $data */
    private function normalizeJson(array $data): array
    {
        if (isset($data['raw_payload']) && is_array($data['raw_payload'])) {
            $data['raw_payload'] = json_encode($data['raw_payload'], JSON_THROW_ON_ERROR);
        }

        return $data;
    }
}
