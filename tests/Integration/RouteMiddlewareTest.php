<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\Router;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Verifies that the extension route file attaches the configured management
 * middleware (admin-only by default) to billing-plan and invoice WRITE routes,
 * while leaving read / confirm / webhook routes untouched.
 *
 * The HTTP-layer tests (e.g. BillingPlanApiTest) drive controllers directly and
 * therefore never exercise the router pipeline, so this test guards the actual
 * route registration against regressions in the security wiring.
 */
final class RouteMiddlewareTest extends TestCase
{
    private Router $router;
    private ApplicationContext $context;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->router = new Router($container);

        // No config loader => config() returns the documented defaults, which is
        // exactly the admin-only management stack we want to assert.
        $this->context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');

        $router = $this->router;
        $context = $this->context;
        require __DIR__ . '/../../routes.php';
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
        // Admin-only management stack from payvia.security.manage_middleware default,
        // with the route's own rate limit appended.
        self::assertSame(
            ['auth', 'admin', $expectedRateLimit],
            $this->middlewareFor($method, $path),
            "Write route {$method} {$path} must require auth + admin and keep its rate limit"
        );
    }

    public function testReadAndPublicRoutesDoNotRequireAdmin(): void
    {
        // Read routes: auth but NOT admin.
        self::assertSame(['auth', 'rate_limit:60,60'], $this->middlewareFor('GET', '/payvia/plans'));
        self::assertSame(['auth', 'rate_limit:60,60'], $this->middlewareFor('GET', '/payvia/invoices'));

        // Payment confirm: auth but NOT admin.
        self::assertSame(['auth', 'rate_limit:60,60'], $this->middlewareFor('POST', '/payvia/payments/confirm'));

        // Webhook: no middleware at all (signature verified inside the pipeline).
        self::assertSame([], $this->middlewareFor('POST', '/payvia/webhooks/{gateway}'));
    }
}
