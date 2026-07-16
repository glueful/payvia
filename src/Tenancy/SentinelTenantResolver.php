<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tenancy;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Single-store fallback: every row lives under the '' sentinel tenant.
 *
 * Constructed inline (by the provider's factory, or as a repository's default) only when no
 * shared `CurrentTenantResolver` is bound; never bound under the shared contract id.
 */
final class SentinelTenantResolver implements PayviaTenantResolver
{
    public function tenantUuid(ApplicationContext $context): string
    {
        return '';
    }
}
