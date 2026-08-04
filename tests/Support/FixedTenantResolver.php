<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\Tenancy\PayviaTenantResolver;

/**
 * Test double resolving to a fixed, NON-EMPTY tenant uuid regardless of context.
 *
 * Unlike {@see \Glueful\Extensions\Payvia\Tenancy\SentinelTenantResolver} (tenantUuid always
 * `''`), this is required wherever a test also exercises
 * {@see \Glueful\Extensions\Payvia\Repositories\CheckoutSubjectGuardRepository}: that
 * repository's `lockAndClaim()`/`release()` both treat an EMPTY `tenantUuid` as invalid input
 * and refuse immediately, whereas the origination ledger tolerates `''` as a legitimate
 * single-store sentinel -- an asymmetry that makes the sentinel resolver unusable for any test
 * that claims a subject guard.
 */
final class FixedTenantResolver implements PayviaTenantResolver
{
    public function __construct(private readonly string $tenantUuid = 'tenantAAAA01')
    {
    }

    public function tenantUuid(ApplicationContext $context): string
    {
        return $this->tenantUuid;
    }
}
