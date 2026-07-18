<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Contracts;

use Glueful\Extensions\Contracts\Payments\PayoutDestination;
use Glueful\Extensions\Contracts\Payments\PayoutRequest;

/**
 * Capability interface for gateways that can execute provider payout
 * transfers, parallel to {@see InitiationCapableGateway} /
 * {@see WebhookCapableGateway}. Every method returns an already-CLASSIFIED
 * array -- `status` is pre-mapped by the gateway onto one of the
 * `glueful/extension-contracts` `PayoutResult` / `PayoutStatusResult` /
 * `DestinationStatus` constant strings (never the provider's raw
 * status/state value). The collector (`PayviaPayoutCollector`) consumes
 * this classified shape directly to build the corresponding VO -- it does
 * not re-map provider-native statuses itself. Implementations perform no
 * persistence -- durability of the attempt row is the collector's
 * responsibility.
 */
interface TransferCapableGateway
{
    /**
     * Execute a transfer to $destination for $request.
     *
     * $providerSafeRef is the provider-facing idempotent reference derived
     * from the caller's canonical per-attempt idempotency key (e.g.
     * "{payoutUuid}:attempt:{n}") -- gateways must use it (or a documented
     * gateway-native idempotency mechanism keyed from it) to de-dupe
     * replays of the same attempt; the colon-delimited key itself is never
     * sent to the provider.
     *
     * Network/timeout/5xx/unparseable responses throw instead of
     * fabricating a status.
     *
     * @return array{
     *     status: string,
     *     provider_ref: ?string,
     *     failure_code: ?string,
     *     failure_reason: ?string,
     *     raw: array<string,mixed>
     * } `status` is one of `PayoutResult`'s constants
     *   (already classified by the gateway -- do not re-map).
     */
    public function transfer(PayoutDestination $destination, PayoutRequest $request, string $providerSafeRef): array;

    /**
     * Recover the outcome of a possibly-completed attempt WITHOUT moving
     * money twice -- each gateway uses its safe mechanism: Paystack
     * verifies the persisted provider-safe reference ($providerSafeRef) via
     * its status-lookup mechanism (never a second `transfer()` call --
     * Paystack rejects a duplicate reference); Stripe replays the identical
     * create request under the same Idempotency-Key ($providerSafeRef),
     * which Stripe de-dupes and returns the original transfer for. This is
     * the recovery path for the exact shape `transferStatus()` cannot
     * handle: an unresolved attempt whose provider-assigned $providerRef
     * was itself never learned (the create's own response was lost).
     *
     * Network/timeout/5xx/unparseable responses throw instead of
     * fabricating a status.
     *
     * @return array{
     *     status: string,
     *     provider_ref: ?string,
     *     failure_code: ?string,
     *     failure_reason: ?string,
     *     raw: array<string,mixed>
     * } `status` is one of `PayoutResult`'s constants
     *   (already classified by the gateway -- do not re-map).
     */
    public function recoverTransfer(
        PayoutDestination $destination,
        PayoutRequest $request,
        string $providerSafeRef,
        ?string $providerRef
    ): array;

    /**
     * Reconcile the current provider-side state of the transfer identified
     * by $providerSafeRef and, once known, the provider's own $providerRef.
     *
     * Network/timeout/5xx/unparseable responses throw instead of
     * fabricating a status.
     *
     * @return array{
     *     status: string,
     *     reversed_amount: int,
     *     provider_ref: ?string,
     *     failure_code: ?string,
     *     failure_reason: ?string,
     *     raw: array<string,mixed>
     * } `status` is one of `PayoutStatusResult`'s constants
     *   (already classified by the gateway -- do not re-map).
     *   `reversed_amount` is the minor-unit amount reversed (0 when none).
     */
    public function transferStatus(string $providerSafeRef, ?string $providerRef): array;

    /**
     * Inspect a payout destination's provider-side readiness for
     * $accountRef (the opaque `PayoutDestination::$accountRef`).
     *
     * @return array{
     *     state: string,
     *     failure_code: ?string
     * } `state` is one of `DestinationStatus`'s constants
     *   (already classified by the gateway -- do not re-map).
     */
    public function inspectAccount(string $accountRef): array;
}
