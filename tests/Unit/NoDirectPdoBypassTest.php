<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Permanent grep-gate: runtime Payvia code must never bypass the query builder with raw PDO
 * or hand-rolled SQL for the five tenant-scoped tables (payments, billing_plans, invoices,
 * gateway_subscriptions, payment_intents). Every read/write must go through a repository
 * method so tenant scoping (or the `ProviderCorrelationRepository::system()` seam) is always
 * applied. `provider_events` is intentionally excluded -- it is the global, tenantless
 * transport/inbox table and its own `executeModification()` raw-SQL counter increment is a
 * known, allowed exception.
 */
final class NoDirectPdoBypassTest extends TestCase
{
    /** @var list<string> */
    private const TENANT_TABLES = [
        'payments',
        'billing_plans',
        'invoices',
        'gateway_subscriptions',
        'payment_intents',
    ];

    public function testNoRawPdoOrRawSqlTouchesTheFiveTenantTables(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            if (preg_match('/getPDO\(\)|executeModification|->query\(|->prepare\(|->exec\(/', $contents) !== 1) {
                continue;
            }

            foreach (self::TENANT_TABLES as $table) {
                if (str_contains($contents, $table)) {
                    $violations[] = $file->getPathname() . ' (' . $table . ')';
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            'Direct-PDO/raw-SQL bypass found for a tenant-scoped table: ' . implode(', ', $violations)
        );
    }
}
