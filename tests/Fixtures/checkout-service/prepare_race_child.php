<?php

/**
 * Race-test child process for SubscriptionCheckoutServiceTest's real-PostgreSQL "different
 * idempotency key, same subject" race (workspace self-serve checkout, Task 5, design spec §3.2).
 *
 * Launched via `proc_open()` from the parent test (mirrors CheckoutOriginationLedgerTest's own
 * `subject_guard_race_child.php` idiom exactly -- PHP has no threads, so a real second actor
 * requires a real second process), but drives the FULL `SubscriptionCheckoutService::prepare()`
 * flow rather than the bare `CheckoutSubjectGuardRepository`: this proves that a losing
 * concurrent claim rolls its own freshly-inserted `preparing` origination row back together with
 * the failed guard claim, under genuine PostgreSQL row-lock contention (not just SQLite's more
 * forgiving, non-poisoning error behavior).
 *
 * argv[1] is a JSON object: {subjectKey, idempotencyKey, originationUuid}. Always runs under the
 * fixed 'tenantAAAA01' tenant (matching the parent test's own `self::TENANT`) -- NOT the empty
 * sentinel: CheckoutSubjectGuardRepository::lockAndClaim() treats an empty tenantUuid as invalid
 * input and refuses immediately, unlike the origination ledger which tolerates it. Prints a JSON
 * result {ok: bool, exception?: string, message?: string} to stdout.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Payvia\Checkout\SubscriptionCheckoutRequest;
use Glueful\Extensions\Payvia\Checkout\SubscriptionCheckoutService;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\CheckoutOriginationRepository;
use Glueful\Extensions\Payvia\Repositories\CheckoutSubjectGuardRepository;
use Glueful\Extensions\Payvia\Tests\Support\FakeSubscriptionInitiationGateway;
use Glueful\Extensions\Payvia\Tests\Support\FixedTenantResolver;
use Psr\Container\ContainerInterface;

const TENANT = 'tenantAAAA01';

$args = json_decode((string) ($argv[1] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

$connection = new Connection([
    'engine' => 'pgsql',
    'pgsql' => [
        'host' => getenv('DB_PGSQL_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PGSQL_PORT') ?: 5432),
        'db' => getenv('DB_PGSQL_DATABASE') ?: 'payvia_test',
        'user' => getenv('DB_PGSQL_USERNAME') ?: 'postgres',
        'pass' => getenv('DB_PGSQL_PASSWORD') ?: '',
        'schema' => getenv('DB_PGSQL_SCHEMA') ?: 'public',
    ],
    'pooling' => ['enabled' => false],
]);

// Never touched for anything but driver resolution; no config loader needed since a registered
// driver satisfies GatewayManager::gateway() regardless of the (here, empty) config map.
$context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');

$fakeGateway = new FakeSubscriptionInitiationGateway();
$container = new class ($fakeGateway) implements ContainerInterface {
    public function __construct(private FakeSubscriptionInitiationGateway $gateway)
    {
    }

    public function get(string $id): mixed
    {
        if ($id === FakeSubscriptionInitiationGateway::class) {
            return $this->gateway;
        }

        throw new \RuntimeException("Unknown service: {$id}");
    }

    public function has(string $id): bool
    {
        return $id === FakeSubscriptionInitiationGateway::class;
    }
};

$gateways = new GatewayManager($container, $context);
$gateways->registerDriver('fakegw', FakeSubscriptionInitiationGateway::class);

// Deliberately pass ONLY the connection (no context) to each repository: BaseRepository::
// getSharedConnection() silently swaps the shared connection for a fresh, unmigrated
// Connection::fromContext() whenever a non-null context is passed alongside a connection that
// itself has no context -- passing both here would replace this PostgreSQL connection with an
// unrelated one built from $context's (empty) config. The origination repository's OWN resolver
// must be the SAME FixedTenantResolver the service uses for the guard (it resolves tenant_uuid
// internally, never trusting a caller-supplied value) -- a mismatch would silently write the
// origination row under a different tenant than the guard row this same call claims.
$service = new SubscriptionCheckoutService(
    originations: new CheckoutOriginationRepository($connection, resolver: new FixedTenantResolver(TENANT)),
    guards: new CheckoutSubjectGuardRepository($connection),
    gateways: $gateways,
    resolver: new FixedTenantResolver(TENANT),
);

$request = new SubscriptionCheckoutRequest(
    originationUuid: (string) $args['originationUuid'],
    tenantUuid: TENANT,
    subjectKey: (string) $args['subjectKey'],
    gateway: 'fakegw',
    providerPlanIdentifier: 'plan_race',
    consumerMetadata: [],
    customerEmail: 'racer@example.test',
    returnUrl: 'https://shop.example.test/return',
    cancelUrl: 'https://shop.example.test/cancel',
    idempotencyKey: (string) $args['idempotencyKey'],
    requiredProjectionConsumer: null,
);

try {
    $service->prepare($context, $request, static function (): void {
    });
    echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    echo json_encode(
        ['ok' => false, 'exception' => get_class($e), 'message' => $e->getMessage()],
        JSON_THROW_ON_ERROR
    );
}
