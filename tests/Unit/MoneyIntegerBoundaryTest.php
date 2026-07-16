<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Grep-gate — integer minor units, no float money arithmetic (2.0.0 hardening,
 * Part 1). Codifies the two textual invariants the runtime sweep must satisfy
 * in `src/`:
 *
 *   - `PaymentService::minorUnits()` is deleted entirely: the storage/event/API
 *     pipeline carries a single integer end-to-end, never multiplying a float
 *     amount by 100 to derive minor units.
 *   - No gateway (or any other src/ file) divides a wire amount by 100 to
 *     convert it to a float major-unit amount; Stripe/Paystack amounts are
 *     already integer minor units on the wire and must pass through untouched.
 *
 * Implemented as an in-code recursive scan (mirroring the documented CI grep)
 * rather than a shell-out, so it runs the same everywhere:
 *
 *   grep -rn "minorUnits" src/     -> zero hits
 *   grep -rn "/ 100" src/          -> zero money-related hits
 */
final class MoneyIntegerBoundaryTest extends TestCase
{
    public function testMinorUnitsHelperNoLongerExistsInSource(): void
    {
        $hits = $this->scan(['minorUnits']);

        self::assertSame(
            [],
            $hits,
            'PaymentService::minorUnits() (and any other minorUnits() helper) must be deleted; '
                . 'the storage/event/API pipeline carries a single integer end-to-end.'
        );
    }

    public function testNoFloatDivisionByOneHundredRemainsInSource(): void
    {
        $hits = $this->scan(['/ 100.0', '/ 100', '/100.0', '/100']);

        self::assertSame(
            [],
            $hits,
            'No src/ file may float-divide a wire amount by 100; gateway amounts are already '
                . 'integer minor units and must pass through untouched.'
        );
    }

    /**
     * @param list<string> $needles
     * @return list<string>
     */
    private function scan(array $needles): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        self::assertDirectoryExists($root);

        $hits = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();

            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $lineNo => $line) {
                foreach ($needles as $needle) {
                    if (str_contains($line, $needle)) {
                        $hits[] = $path . ':' . ($lineNo + 1) . ' ' . trim($line);
                    }
                }
            }
        }

        return $hits;
    }
}
