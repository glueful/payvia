<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Events;

use Glueful\Extensions\Payvia\Contracts\PaymentProviderEventInterface;

final class ProviderEvent implements PaymentProviderEventInterface
{
    /**
     * @param array<string,mixed> $normalized
     * @param array<string,mixed> $raw
     */
    private function __construct(
        private string $gateway,
        private string $type,
        private ?string $providerEventId,
        private string $deliveryKey,
        private string $logicalEventKey,
        private \DateTimeImmutable $occurredAt,
        private array $normalized,
        private array $raw,
    ) {
    }

    /**
     * @param array<string,mixed> $normalized
     * @param array<string,mixed> $raw
     */
    public static function create(
        string $gateway,
        string $type,
        ?string $providerEventId,
        string $deliveryKey,
        string $entityId,
        \DateTimeImmutable $occurredAt,
        array $normalized,
        array $raw,
        ?string $discriminator = null,
    ): self {
        return new self(
            $gateway,
            $type,
            $providerEventId,
            $deliveryKey,
            self::deriveLogicalKey($type, $entityId, $normalized, $discriminator),
            $occurredAt,
            $normalized,
            $raw,
        );
    }

    /**
     * @param array<string,mixed> $normalized
     * @param array<string,mixed> $raw
     */
    public static function fromStored(
        string $gateway,
        string $type,
        ?string $providerEventId,
        string $deliveryKey,
        string $logicalEventKey,
        \DateTimeImmutable $occurredAt,
        array $normalized,
        array $raw,
    ): self {
        return new self(
            $gateway,
            $type,
            $providerEventId,
            $deliveryKey,
            $logicalEventKey,
            $occurredAt,
            $normalized,
            $raw,
        );
    }

    /** @param array<string,mixed> $normalized */
    private static function deriveLogicalKey(
        string $type,
        string $entityId,
        array $normalized,
        ?string $discriminator,
    ): string {
        if (EventType::isImmutable($type)) {
            return $type . ':' . $entityId;
        }

        if ($discriminator !== null && $discriminator !== '') {
            return $type . ':' . $entityId . ':' . $discriminator;
        }

        $hash = substr(hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR)), 0, 16);
        return $type . ':' . $entityId . ':' . $hash;
    }

    public function gateway(): string
    {
        return $this->gateway;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function providerEventId(): ?string
    {
        return $this->providerEventId;
    }

    public function deliveryKey(): string
    {
        return $this->deliveryKey;
    }

    public function logicalEventKey(): string
    {
        return $this->logicalEventKey;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function normalized(): array
    {
        return $this->normalized;
    }

    public function raw(): array
    {
        return $this->raw;
    }
}
