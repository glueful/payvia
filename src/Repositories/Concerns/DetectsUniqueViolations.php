<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Repositories\Concerns;

/**
 * Shared detection for database unique-constraint violations.
 *
 * Payment providers retry webhooks and clients retry confirmations, so the
 * same logical event can be processed concurrently. A find-then-insert (TOCTOU)
 * can therefore lose the race and hit a UNIQUE constraint on the insert. Call
 * sites use this to detect that specific failure and recover via the update path,
 * while letting all other exceptions propagate.
 */
trait DetectsUniqueViolations
{
    /**
     * Determine whether the given throwable represents a unique-constraint violation.
     *
     * Driver-agnostic: matches SQLite ("UNIQUE constraint failed"), the SQLSTATE
     * integrity-constraint codes 23000 (MySQL) and 23505 (PostgreSQL), and any
     * message that mentions "unique".
     */
    public function isUniqueViolation(\Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'UNIQUE')
            || str_contains(strtolower($message), 'unique')
            || str_contains($message, '23000')
            || str_contains($message, '23505');
    }
}
