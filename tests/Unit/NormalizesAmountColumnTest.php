<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Unit;

use Glueful\Extensions\Payvia\Repositories\Concerns\NormalizesAmountColumn;
use PHPUnit\Framework\TestCase;

/**
 * `amount` is a bigint/integer minor-unit column on every domain table
 * (payments, invoices, billing_plans, payment_intents). PDO's return type
 * for integer columns is driver-dependent (some MySQL/Postgres
 * configurations round-trip integer columns as numeric strings), so read
 * paths cannot rely on `amount` already being a PHP int -- this is the read-
 * boundary cast that matches the (int) cast controllers already apply on
 * write.
 */
final class NormalizesAmountColumnTest extends TestCase
{
    use NormalizesAmountColumn;

    public function testCastsStringAmountToInt(): void
    {
        $row = $this->normalizeAmountColumn(['uuid' => 'abc', 'amount' => '5000', 'currency' => 'GHS']);

        self::assertIsInt($row['amount']);
        self::assertSame(5000, $row['amount']);
    }

    public function testLeavesIntAmountUnchanged(): void
    {
        $row = $this->normalizeAmountColumn(['amount' => 5000]);

        self::assertIsInt($row['amount']);
        self::assertSame(5000, $row['amount']);
    }

    public function testLeavesMissingAmountKeyUntouched(): void
    {
        $row = $this->normalizeAmountColumn(['uuid' => 'abc']);

        self::assertArrayNotHasKey('amount', $row);
    }

    public function testLeavesNullAmountAsNull(): void
    {
        $row = $this->normalizeAmountColumn(['amount' => null]);

        self::assertNull($row['amount']);
    }

    public function testNormalizesAmountAcrossMultipleRows(): void
    {
        $rows = $this->normalizeAmountColumns([
            ['uuid' => 'a', 'amount' => '1000'],
            ['uuid' => 'b', 'amount' => '2000'],
        ]);

        self::assertIsInt($rows[0]['amount']);
        self::assertIsInt($rows[1]['amount']);
        self::assertSame(1000, $rows[0]['amount']);
        self::assertSame(2000, $rows[1]['amount']);
    }
}
