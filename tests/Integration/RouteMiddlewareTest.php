<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\Router;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Verifies that the extension route file composes the three ordered middleware profiles
 * (`payvia.security.auth_middleware` -> `tenant_context_middleware` -> `manage_middleware`,
 * per the spec's "Tenant context on HTTP routes" section) onto billing-plan and invoice
 * routes in the documented order, while leaving the webhook route untouched.
 *
 * The HTTP-layer tests (e.g. BillingPlanApiTest) drive controllers directly and
 * therefore never exercise the router pipeline, so this test guards the actual
 * route registration against regressions in the security wiring. The fail-closed half
 * of the contract (profile 2 establishes no tenant context) is proven separately in
 * {@see \Glueful\Extensions\Payvia\Tests\Integration\Tenancy\RouteProfileFailClosedTest},
 * against the exact resolver factory the DI container uses for these routes.
 */
final class RouteMiddlewareTest extends TestCase
{
    private Router $router;
    private ApplicationContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadRoutes();
    }

    /**
     * (Re)builds a fresh Router + ApplicationContext and requires routes.php, optionally
     * merging `payvia.*` config-default overrides first so `config($context, 'payvia...')`
     * picks them up instead of routes.php's own inline defaults.
     *
     * @param array<string,mixed> $configOverrides
     */
    private function loadRoutes(array $configOverrides = []): void
    {
        // Container that advertises no services: keeps the Router cache disabled
        // (no ApplicationContext::class binding) so we inspect freshly registered
        // routes rather than a compiled cache.
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException("Unknown service: {$id}");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        $router = new Router($container);

        // No config loader => config() returns the documented per-profile defaults,
        // unless a test merges its own config defaults below.
        $context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        if ($configOverrides !== []) {
            $context->mergeConfigDefaults('payvia', $configOverrides);
        }

        require __DIR__ . '/../../routes.php';

        $this->router = $router;
        $this->context = $context;
    }

    /** @return array<int,string> */
    private function middlewareFor(string $method, string $path): array
    {
        foreach ($this->router->getAllRoutes() as $route) {
            if ($route['method'] === $method && $route['path'] === $path) {
                /** @var array<int,string> $mw */
                $mw = $route['middleware'];
                return $mw;
            }
        }
        self::fail("Route {$method} {$path} was not registered");
    }

    /**
     * @return array<string, array{0:string, 1:string, 2:string}>
     */
    public static function writeRouteProvider(): array
    {
        return [
            'create plan'    => ['POST', '/payvia/plans', 'rate_limit:30,60'],
            'update plan'    => ['POST', '/payvia/plans/update', 'rate_limit:30,60'],
            'disable plan'   => ['POST', '/payvia/plans/disable', 'rate_limit:30,60'],
            'create invoice' => ['POST', '/payvia/invoices', 'rate_limit:60,60'],
            'mark paid'      => ['POST', '/payvia/invoices/mark-paid', 'rate_limit:60,60'],
            'cancel invoice' => ['POST', '/payvia/invoices/cancel', 'rate_limit:60,60'],
        ];
    }

    /**
     * @dataProvider writeRouteProvider
     */
    public function testWriteRoutesRequireAdminPlusRateLimit(
        string $method,
        string $path,
        string $expectedRateLimit
    ): void {
        // v1 single-store default equivalence: no config-loader/defaults bound, so profile 1
        // (auth_middleware, default ['auth']) -> profile 2 (tenant_context_middleware, default
        // [], contributing nothing) -> profile 3 (manage_middleware, default ['admin'])
        // composes byte-identical to v1's old `['auth', 'admin']` manage_middleware default,
        // with the route's own rate limit appended.
        self::assertSame(
            ['auth', 'admin', $expectedRateLimit],
            $this->middlewareFor($method, $path),
            "Write route {$method} {$path} must require auth + admin and keep its rate limit"
        );
    }

    public function testReadAndPublicRoutesDoNotRequireAdmin(): void
    {
        // Read/confirm routes: profile 1 -> 2 only (auth, no admin) -- empty profile 2
        // contributes nothing under single-store defaults.
        self::assertSame(['auth', 'rate_limit:60,60'], $this->middlewareFor('GET', '/payvia/plans'));
        self::assertSame(['auth', 'rate_limit:60,60'], $this->middlewareFor('GET', '/payvia/invoices'));

        // Payment confirm: auth but NOT admin.
        self::assertSame(['auth', 'rate_limit:60,60'], $this->middlewareFor('POST', '/payvia/payments/confirm'));

        // Webhook: no middleware at all (signature verified inside the pipeline); uses none
        // of the three profiles.
        self::assertSame([], $this->middlewareFor('POST', '/payvia/webhooks/{gateway}'));
    }

    /**
     * Ordering: with all three profiles configured to distinct, host-chosen values, every
     * route's composed middleware list must preserve profile order exactly -- 1 -> 2 for
     * read/confirm routes, 1 -> 2 -> 3 for management routes -- and the webhook route must
     * remain untouched by any of them. Payvia itself never names these aliases; this proves
     * only that composition order is correct, not any particular alias.
     */
    public function testProfilesComposeInDocumentedOrderWhenAllThreeAreConfigured(): void
    {
        $this->loadRoutes([
            'security' => [
                'auth_middleware' => ['custom_auth'],
                'tenant_context_middleware' => ['tenant_probe'],
                'manage_middleware' => ['custom_admin'],
            ],
        ]);

        // Read/confirm routes: profile 1 -> 2.
        self::assertSame(
            ['custom_auth', 'tenant_probe', 'rate_limit:60,60'],
            $this->middlewareFor('GET', '/payvia/plans')
        );
        self::assertSame(
            ['custom_auth', 'tenant_probe', 'rate_limit:60,60'],
            $this->middlewareFor('GET', '/payvia/invoices')
        );
        self::assertSame(
            ['custom_auth', 'tenant_probe', 'rate_limit:60,60'],
            $this->middlewareFor('POST', '/payvia/payments/confirm')
        );

        // Management routes: profile 1 -> 2 -> 3.
        self::assertSame(
            ['custom_auth', 'tenant_probe', 'custom_admin', 'rate_limit:30,60'],
            $this->middlewareFor('POST', '/payvia/plans')
        );
        self::assertSame(
            ['custom_auth', 'tenant_probe', 'custom_admin', 'rate_limit:30,60'],
            $this->middlewareFor('POST', '/payvia/plans/update')
        );
        self::assertSame(
            ['custom_auth', 'tenant_probe', 'custom_admin', 'rate_limit:30,60'],
            $this->middlewareFor('POST', '/payvia/plans/disable')
        );
        self::assertSame(
            ['custom_auth', 'tenant_probe', 'custom_admin', 'rate_limit:60,60'],
            $this->middlewareFor('POST', '/payvia/invoices')
        );
        self::assertSame(
            ['custom_auth', 'tenant_probe', 'custom_admin', 'rate_limit:60,60'],
            $this->middlewareFor('POST', '/payvia/invoices/mark-paid')
        );
        self::assertSame(
            ['custom_auth', 'tenant_probe', 'custom_admin', 'rate_limit:60,60'],
            $this->middlewareFor('POST', '/payvia/invoices/cancel')
        );

        // Webhook: still untouched by any configured profile.
        self::assertSame([], $this->middlewareFor('POST', '/payvia/webhooks/{gateway}'));
    }
}
