<?php

/**
 * Race-test child process for CheckoutOriginationLedgerTest's real-PostgreSQL guard race lane.
 *
 * Deliberately NOT a PHPUnit test file: it is launched via `proc_open()` from the parent test
 * (mirrors thallo's ProductLinkRaceTest/ShopCheckoutRaceTest `launchRaceChild()` harness shape)
 * so the guard's INSERT-time unique-index race can be exercised against a genuinely concurrent,
 * independent connection -- PHP has no threads, so a real second actor requires a real second
 * process. Needs no application boot: {@see CheckoutSubjectGuardRepository} only ever needs a
 * raw {@see Connection}, never the container, so this stays a minimal standalone script.
 *
 * argv[1] is a JSON object: {tenant, subjectKey, originationUuid}. Prints a JSON result
 * {ok: bool} (or {ok: false, error: string} on an unexpected throwable) to stdout.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Payvia\Repositories\CheckoutSubjectGuardRepository;

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

// Never touched by CheckoutSubjectGuardRepository's methods, but required by their signature.
$context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');

try {
    $repo = new CheckoutSubjectGuardRepository($connection);
    $ok = $repo->lockAndClaim(
        $context,
        (string) $args['tenant'],
        (string) $args['subjectKey'],
        (string) $args['originationUuid'],
    );
    echo json_encode(['ok' => $ok], JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR);
}
