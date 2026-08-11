<?php

/**
 * Race-test child process for PayviaPaymentCollectorEnsureLiveTest's real-PostgreSQL
 * concurrent-renewal lane (payment-links Task 2).
 *
 * Deliberately NOT a PHPUnit test file: it is launched via `proc_open()` from the parent test
 * (mirrors tests/Fixtures/checkout-origination/subject_guard_race_child.php, itself modelled on
 * thallo's `launchRaceChild()` harness) so that a genuinely concurrent, independent connection
 * runs the SAME ensure-live renewal against the SAME payable -- PHP has no threads, so a real
 * second actor requires a real second process.
 *
 * Both processes wait on a gate file the parent touches, so they enter the renewal window
 * together. The fake gateway mirrors the parent's: the seeded session is confirmed dead, and the
 * new session id derives from the attempt uuid the collector claimed -- so "converged on one
 * attempt" and "converged on one provider session" are the same observation.
 *
 * argv[1] is a JSON object: {payableId, gate}. Prints a JSON result
 * {ok: bool, reference?: string} (or {ok: false, error: string}) to stdout.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Payvia\Contracts\HostedSessionRenewalCapableGateway;
use Glueful\Extensions\Payvia\Contracts\HostedSessionStateCapableGateway;
use Glueful\Extensions\Payvia\Contracts\InitiationCapableGateway;
use Glueful\Extensions\Payvia\Contracts\PaymentGatewayInterface;
use Glueful\Extensions\Payvia\GatewayManager;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;
use Glueful\Extensions\Payvia\Services\PayviaPaymentCollector;
use Psr\Container\ContainerInterface;

final class RaceChildGateway implements
    PaymentGatewayInterface,
    InitiationCapableGateway,
    HostedSessionStateCapableGateway,
    HostedSessionRenewalCapableGateway
{
    public function verify(string $reference, array $options = []): array
    {
        return ['status' => 'success', 'reference' => $reference];
    }

    public function initialize(PayableReference $payable, array $options = []): array
    {
        $attempt = (string) ($options['attempt_uuid'] ?? '');
        if ($attempt === '') {
            throw new \RuntimeException('race child expected an attempt_uuid');
        }
        usleep(250_000);

        return [
            'reference' => 'sess_' . $attempt,
            'checkout_url' => 'https://checkout.test/' . $attempt,
        ];
    }

    public function hostedSessionState(string $reference): string
    {
        return self::STATE_DEAD;
    }

    public function abandonHostedSession(string $reference): string
    {
        return self::RENEWAL_CONFIRMED_DEAD;
    }
}

$args = json_decode((string) ($argv[1] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

try {
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

    $gateway = new RaceChildGateway();
    $container = new class ($connection, $gateway) implements ContainerInterface {
        public function __construct(private Connection $connection, private RaceChildGateway $gateway)
        {
        }

        public function get(string $id): mixed
        {
            if ($id === 'database' || $id === Connection::class) {
                return $this->connection;
            }
            if ($id === RaceChildGateway::class) {
                return $this->gateway;
            }

            throw new \RuntimeException("Unknown service: {$id}");
        }

        public function has(string $id): bool
        {
            return in_array($id, ['database', Connection::class, RaceChildGateway::class], true);
        }
    };

    $context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
    $context->setContainer($container);

    $config = require dirname(__DIR__, 3) . '/config/payvia.php';
    $config['default_gateway'] = 'fake';
    $config['gateways']['fake'] = ['enabled' => true, 'driver' => 'fake'];
    $context->mergeConfigDefaults('payvia', $config);

    $manager = new GatewayManager($container, $context);
    $manager->registerDriver('fake', RaceChildGateway::class);

    $collector = new PayviaPaymentCollector($manager, new PaymentIntentRepository($connection));
    $payable = new PayableReference(
        'commerce_order',
        (string) $args['payableId'],
        4999,
        'GHS',
        'Race order',
        ['callback_url' => 'https://shop.test/return'],
    );

    // Start together with the parent.
    $gate = (string) $args['gate'];
    $deadline = microtime(true) + 10.0;
    while (!file_exists($gate) && microtime(true) < $deadline) {
        usleep(2_000);
    }

    $result = $collector->initiate($context, $payable);
    echo json_encode([
        'ok' => $result->status === 'ok',
        'reference' => $result->payload['reference'] ?? null,
        'checkout_url' => $result->payload['checkout_url'] ?? null,
    ], JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => get_class($e) . ': ' . $e->getMessage(),
    ], JSON_THROW_ON_ERROR);
}
