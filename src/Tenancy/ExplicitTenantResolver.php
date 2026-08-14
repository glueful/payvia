<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tenancy;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Resolves to ONE operator-named tenant, regardless of context.
 *
 * The console seam, and only the console seam. Every request-time resolver answers from something
 * the request carries (a host header, a session, a claim); a scheduled or hand-run command has no
 * request at all, so on a tenancy-enabled host {@see FailClosedTenantResolver} correctly refuses
 * every CLI invocation — correctly, because silently sweeping the '' sentinel partition instead
 * would be worse. That leaves batch maintenance with no way to name its partition, which is what
 * this class supplies: `--tenant` states the partition explicitly, in the audit trail, per run.
 *
 * NEVER bind this under {@see PayviaTenantResolver} for a request-serving container: a fixed
 * tenant that ignores the request is exactly the cross-tenant leak the fail-closed wrapper exists
 * to prevent. Construct it inline, for the single command run that named the tenant.
 *
 * An empty uuid is rejected at construction rather than resolving to '' — the sentinel partition
 * is {@see SentinelTenantResolver}'s meaning, and reaching it by omitting an option's value must
 * never look the same as asking for it.
 */
final class ExplicitTenantResolver implements PayviaTenantResolver
{
    private readonly string $tenantUuid;

    public function __construct(string $tenantUuid)
    {
        $tenantUuid = trim($tenantUuid);
        if ($tenantUuid === '') {
            throw new \InvalidArgumentException('Tenant uuid is required.');
        }

        $this->tenantUuid = $tenantUuid;
    }

    public function tenantUuid(ApplicationContext $context): string
    {
        return $this->tenantUuid;
    }
}
