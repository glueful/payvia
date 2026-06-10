<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Support;

use Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface;
use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;
use Glueful\Extensions\Payvia\Contracts\SubscriptionCapableGateway;
use Glueful\Extensions\Payvia\Contracts\WebhookCapableGateway;
use Glueful\Extensions\Payvia\Events\EventType;
use Glueful\Extensions\Payvia\Events\ProviderEvent;

final class FakeWebhookGateway implements PaymentGatewayInterface, WebhookCapableGateway, SubscriptionCapableGateway
{
    public bool $signatureValid = true;

    /** @var array<string,mixed> */
    public array $fetchResult = [];

    public function verify(string $reference, array $options = []): array
    {
        return [
            'status' => 'success',
            'id' => $options['id'] ?? null,
            'reference' => $reference,
            'amount' => 1.0,
            'currency' => 'GHS',
        ];
    }

    public function verifyWebhookSignature(string $rawBody, array $headers): bool
    {
        return $this->signatureValid;
    }

    public function parseWebhookEvent(string $rawBody, array $headers): PaymentProviderEventInterface
    {
        /** @var array<string,mixed> $payload */
        $payload = json_decode($rawBody, true) ?: [];
        $type = (string) ($payload['type'] ?? EventType::UNKNOWN);
        $entityId = (string) ($payload['entity_id'] ?? 'X');

        return ProviderEvent::create(
            gateway: (string) ($payload['gateway'] ?? 'fake'),
            type: $type,
            providerEventId: isset($payload['provider_event_id']) ? (string) $payload['provider_event_id'] : null,
            deliveryKey: (string) ($payload['delivery_key'] ?? hash('sha256', $rawBody)),
            entityId: $entityId,
            occurredAt: new \DateTimeImmutable(),
            normalized: (array) ($payload['normalized'] ?? []),
            raw: $payload,
            discriminator: isset($payload['discriminator']) ? (string) $payload['discriminator'] : null,
        );
    }

    public function fetchSubscription(string $gatewaySubscriptionId): array
    {
        return $this->fetchResult !== []
            ? $this->fetchResult
            : ['gateway_subscription_id' => $gatewaySubscriptionId, 'status' => 'active'];
    }

    public function cancelSubscription(string $gatewaySubscriptionId, bool $atPeriodEnd = true): array
    {
        return ['gateway_subscription_id' => $gatewaySubscriptionId, 'status' => 'canceled'];
    }
}
