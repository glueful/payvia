<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Repositories\Concerns;

/**
 * Shared normalization for the `amount` column on read.
 *
 * `amount` is stored as a bigint/integer minor-unit column on every domain
 * table (payments, invoices, billing_plans, payment_intents). PDO's return
 * type for integer columns is driver- and configuration-dependent (e.g. some
 * MySQL/Postgres setups round-trip integer columns as numeric strings), so a
 * row read back from any of these tables cannot rely on `amount` already
 * being a PHP int. Writes already cast to int at the controller boundary
 * before persisting; this is the matching cast at the read boundary so every
 * API response carries `amount` as an int regardless of driver behavior.
 */
trait NormalizesAmountColumn
{
    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function normalizeAmountColumn(array $row): array
    {
        if (array_key_exists('amount', $row) && $row['amount'] !== null) {
            $row['amount'] = (int) $row['amount'];
        }

        return $row;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public function normalizeAmountColumns(array $rows): array
    {
        return array_map($this->normalizeAmountColumn(...), $rows);
    }
}
