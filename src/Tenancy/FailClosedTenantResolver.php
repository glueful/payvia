<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRequiredException;

/**
 * Tenant-mode guard: wraps the shared `CurrentTenantResolver` when a host tenancy package is
 * bound. A resolved '' means no tenant context was established for this request (the host's
 * `payvia.security.tenant_context_middleware` profile never ran, or ran and failed) -- fail
 * closed instead of silently reading/writing the single-store sentinel partition.
 */
final class FailClosedTenantResolver implements PayviaTenantResolver
{
    public function __construct(private readonly CurrentTenantResolver $inner)
    {
    }

    public function tenantUuid(ApplicationContext $context): string
    {
        $tenantUuid = $this->inner->tenantUuid($context);
        if ($tenantUuid === '') {
            throw new TenantContextRequiredException('Payvia tenant context is required.');
        }

        return $tenantUuid;
    }
}
