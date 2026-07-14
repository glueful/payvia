<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tenancy;

use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;

/**
 * Payvia-local tenant resolution seam.
 *
 * Every interactive Payvia repository (Payment, Invoice, BillingPlan, GatewaySubscription,
 * PaymentIntent) depends on THIS interface, never on the shared `CurrentTenantResolver`
 * contract directly. `PayviaServiceProvider` binds ONLY this local interface: when a host
 * tenancy package (e.g. glueful/tenancy) has bound the shared contract, the provider validates
 * it and wraps it fail-closed (see FailClosedTenantResolver); otherwise it falls back to the
 * single-store sentinel (see SentinelTenantResolver). Payvia never binds or replaces the shared
 * `CurrentTenantResolver` contract itself, so other extensions sharing the same host container
 * are unaffected by Payvia's tenancy posture.
 */
interface PayviaTenantResolver extends CurrentTenantResolver
{
}
