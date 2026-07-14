<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRequiredException;
use Glueful\Extensions\Payvia\Database\Migrations\CreateInvoicesTable;
use Glueful\Extensions\Payvia\PayviaServiceProvider;
use Glueful\Extensions\Payvia\Repositories\InvoiceRepository;
use Glueful\Extensions\Payvia\Tenancy\FailClosedTenantResolver;
use Glueful\Extensions\Payvia\Tenancy\SentinelTenantResolver;
use Glueful\Extensions\Payvia\Tests\Support\PayviaTestCase;
use Psr\Container\ContainerInterface;

/**
 * Proves the fail-closed half of the route middleware profile contract (spec §"Tenant context
 * on HTTP routes"): `payvia.security.tenant_context_middleware` (profile 2) is what a
 * tenancy-enabled host configures to populate request-scoped tenant context before Payvia's
 * repositories run behind the authenticated `/payvia/*` routes.
 *
 * `PayviaServiceProvider::makePayviaTenantResolver()` is the exact factory the DI container
 * invokes to build the `PayviaTenantResolver` every one of those routes' repositories consumes
 * -- this test drives that factory directly (not a hand-rolled fake) so the outcome is proven
 * against production wiring, not a stand-in:
 *
 *  - shared `CurrentTenantResolver` bound (host has installed a tenancy package) but profile 2
 *    never ran, or ran and failed to establish context -- the shared resolver still reports ''
 *    -- the factory returns a `FailClosedTenantResolver`, and any repository call reached
 *    through a route rejects with `TenantContextRequiredException` instead of silently reading/
 *    writing the '' single-store partition;
 *  - no shared resolver bound at all (v1 single-store, matching `RouteMiddlewareTest`'s default
 *    equivalence assertions) -- the factory returns `SentinelTenantResolver`, and the identical
 *    repository call keeps working exactly as before.
 */
final class RouteProfileFailClosedTest extends PayviaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $schema = $this->connection->getSchemaBuilder();
        (new CreateInvoicesTable())->up($schema);
    }

    public function testFailClosedResolverRejectsRouteRepositoryCallWhenProfileTwoEstablishesNoContext(): void
    {
        // Simulates a tenancy-enabled host where the request never ran (or ran and failed)
        // `payvia.security.tenant_context_middleware` -- the shared resolver still reports ''
        // because nothing populated request-scoped tenant context.
        $sharedResolverWithNoContext = new class implements CurrentTenantResolver {
            public function tenantUuid(ApplicationContext $context): string
            {
                return '';
            }
        };

        $resolver = PayviaServiceProvider::makePayviaTenantResolver(
            $this->containerWithSharedResolver($sharedResolverWithNoContext)
        );
        self::assertInstanceOf(FailClosedTenantResolver::class, $resolver);

        // The same resolver a route's repository would receive from the container.
        $repo = new InvoiceRepository($this->connection, resolver: $resolver);

        $this->expectException(TenantContextRequiredException::class);
        $repo->list($this->context);
    }

    public function testFailClosedResolverRejectsOnWriteRouteRepositoryCallToo(): void
    {
        $sharedResolverWithNoContext = new class implements CurrentTenantResolver {
            public function tenantUuid(ApplicationContext $context): string
            {
                return '';
            }
        };

        $resolver = PayviaServiceProvider::makePayviaTenantResolver(
            $this->containerWithSharedResolver($sharedResolverWithNoContext)
        );
        $repo = new InvoiceRepository($this->connection, resolver: $resolver);

        $this->expectException(TenantContextRequiredException::class);
        $repo->createInvoice($this->context, ['number' => 'INV-1', 'amount' => 1000]);
    }

    public function testSingleStoreDefaultRemainsByteIdenticalWithNoSharedResolverBound(): void
    {
        // No shared `CurrentTenantResolver` bound at all -- v1 single-store default. Same
        // factory, same repository call: resolves to the sentinel resolver and keeps working.
        $resolver = PayviaServiceProvider::makePayviaTenantResolver($this->containerWithoutSharedResolver());
        self::assertInstanceOf(SentinelTenantResolver::class, $resolver);

        $repo = new InvoiceRepository($this->connection, resolver: $resolver);
        $repo->createInvoice($this->context, ['number' => 'INV-SENTINEL', 'amount' => 1000]);

        $rows = $repo->list($this->context);
        self::assertCount(1, $rows);

        $row = $this->connection->table('invoices')->where(['number' => 'INV-SENTINEL'])->first();
        self::assertSame('', $row['tenant_uuid']);
    }

    private function containerWithSharedResolver(CurrentTenantResolver $shared): ContainerInterface
    {
        return new class ($shared) implements ContainerInterface {
            public function __construct(private CurrentTenantResolver $shared)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === CurrentTenantResolver::class) {
                    return $this->shared;
                }

                throw new \RuntimeException("Unknown service: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === CurrentTenantResolver::class;
            }
        };
    }

    private function containerWithoutSharedResolver(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException("Unknown service: {$id}");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }
}
