<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Support;

use Glueful\Extensions\Contracts\Payments\DestinationStatus;
use Glueful\Extensions\Contracts\Payments\PayoutDestination;
use Glueful\Extensions\Contracts\Payments\PayoutRequest;
use Glueful\Extensions\Contracts\Payments\PayoutResult;
use Glueful\Extensions\Contracts\Payments\PayoutStatusResult;
use Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface;
use Glueful\Extensions\Payvia\Contracts\TransferCapableGateway;

final class FakeTransferGateway implements PaymentGatewayInterface, TransferCapableGateway
{
    public int $transferCalls = 0;
    public int $transferStatusCalls = 0;
    public int $recoverTransferCalls = 0;
    public int $inspectAccountCalls = 0;

    /**
     * Provider-safe references seen by transfer(), in call order -- lets a
     * test assert a recovery replay reused the identical idempotency key.
     *
     * @var list<string>
     */
    public array $transferProviderSafeRefs = [];

    /**
     * How recoverTransfer() recovers an unresolved (lost-response) attempt:
     * - 'status' (default): verify-by-reference via transferStatus() --
     *   mirrors Paystack (never replays transfer()).
     * - 'replay': replay the identical create via transfer() under the same
     *   provider-safe reference -- mirrors Stripe's idempotent-replay
     *   recovery (Stripe de-dupes and returns the original).
     */
    public string $recoverTransferMode = 'status';

    /** Thrown from transfer() (once set) instead of returning transferResult -- simulates a lost response. */
    public ?\Throwable $transferException = null;
    /** Thrown from transferStatus() (once set) instead of returning transferStatusResult. */
    public ?\Throwable $transferStatusException = null;

    /**
     * Already-classified shape, matching {@see TransferCapableGateway::transfer()}.
     *
     * @var array<string,mixed>
     */
    public array $transferResult = [
        'status' => PayoutResult::PAID,
        'provider_ref' => 'fake_ref_1',
        'failure_code' => null,
        'failure_reason' => null,
        'raw' => [],
    ];

    /**
     * Already-classified shape, matching {@see TransferCapableGateway::transferStatus()}.
     *
     * @var array<string,mixed>
     */
    public array $transferStatusResult = [
        'status' => PayoutStatusResult::PAID,
        'reversed_amount' => 0,
        'provider_ref' => 'fake_ref_1',
        'failure_code' => null,
        'failure_reason' => null,
        'raw' => [],
    ];

    /**
     * Already-classified shape, matching {@see TransferCapableGateway::inspectAccount()}.
     *
     * @var array<string,mixed>
     */
    public array $inspectAccountResult = [
        'state' => DestinationStatus::READY,
        'failure_code' => null,
    ];

    public function verify(string $reference, array $options = []): array
    {
        return [
            'status' => 'success',
            'reference' => $reference,
            'amount' => 100,
            'currency' => 'GHS',
        ];
    }

    public function transfer(PayoutDestination $destination, PayoutRequest $request, string $providerSafeRef): array
    {
        $this->transferCalls++;
        $this->transferProviderSafeRefs[] = $providerSafeRef;

        if ($this->transferException !== null) {
            throw $this->transferException;
        }

        return $this->transferResult;
    }

    public function recoverTransfer(
        PayoutDestination $destination,
        PayoutRequest $request,
        string $providerSafeRef,
        ?string $providerRef
    ): array {
        $this->recoverTransferCalls++;

        return $this->recoverTransferMode === 'replay'
            ? $this->transfer($destination, $request, $providerSafeRef)
            : $this->transferStatus($providerSafeRef, $providerRef);
    }

    public function transferStatus(string $providerSafeRef, ?string $providerRef): array
    {
        $this->transferStatusCalls++;

        if ($this->transferStatusException !== null) {
            throw $this->transferStatusException;
        }

        return $this->transferStatusResult;
    }

    public function inspectAccount(string $accountRef): array
    {
        $this->inspectAccountCalls++;

        return $this->inspectAccountResult;
    }
}
