<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Console;

use Glueful\Console\BaseCommand;
use Glueful\Database\Connection;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;
use Glueful\Extensions\Payvia\Services\StaleIntentSweeper;
use Glueful\Extensions\Payvia\Tenancy\ExplicitTenantResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Retire abandoned `payment_intents` attempts (OUTSTANDING: orphan-intent expiry/sweeper).
 *
 * Intended to run on a schedule (daily is ample at the default 30-day window). One run retires at
 * most `--limit` rows; run it again -- or let the next scheduled run do it -- to drain a larger
 * backlog. Concurrent runs are safe: every retirement is a per-row compare-and-swap.
 *
 * See {@see StaleIntentSweeper} for why age is the only criterion and why sweeping is
 * non-destructive (a returning payer converges via ensure-live's create path).
 *
 * TENANCY: a sweep is tenant-scoped like every other Payvia repository operation, and a command
 * has no request for a host tenancy package to resolve a tenant FROM -- so on a tenancy-enabled
 * host the container's {@see \Glueful\Extensions\Payvia\Tenancy\FailClosedTenantResolver} refuses
 * every unqualified CLI run (correctly: sweeping the '' sentinel partition instead would be
 * silent and wrong). `--tenant` is how a run names its partition, mirroring `payvia:tenancy:adopt`;
 * a tenancy-enabled host loops it over its tenants, or schedules {@see StaleIntentSweeper::sweep()}
 * directly through its own per-tenant scheduler. Single-store installs need nothing: the container's
 * sentinel resolver already answers, and the option stays absent.
 */
#[AsCommand(
    name: 'payvia:intents:sweep-stale',
    description: 'Retire stale open/initializing Payvia payment intents, freeing their idempotency ports'
)]
final class SweepStaleIntentsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_OPTIONAL,
            'Maximum intents to retire in this run',
            StaleIntentSweeper::DEFAULT_BATCH_LIMIT
        );
        $this->addOption(
            'stale-after-days',
            null,
            InputOption::VALUE_OPTIONAL,
            'Override payvia.intents.stale_after_days for this run (clamped to '
                . StaleIntentSweeper::MIN_STALE_AFTER_DAYS . '..' . StaleIntentSweeper::MAX_STALE_AFTER_DAYS . ')'
        );
        $this->addOption(
            'tenant',
            null,
            InputOption::VALUE_REQUIRED,
            'Tenant uuid to sweep (required on tenancy-enabled hosts; omit on single-store installs)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->getContext();

        // Both numeric options are validated, never cast. `(int) 'daily'` is 0, and a silent 0
        // means something drastic on each: for the window, the clamp turns it into a 1-day sweep
        // -- thirty times more aggressive than the default, retiring checkouts started yesterday;
        // for the batch, a cap of nothing at all, i.e. a sweep that reports success and does
        // nothing forever. A typo in a crontab must be a loud failure, not either of those.
        try {
            $limit = $this->integerOption($input, 'limit') ?? StaleIntentSweeper::DEFAULT_BATCH_LIMIT;
            $days = $this->integerOption($input, 'stale-after-days');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($limit < 1) {
            $this->error('The --limit option must be at least 1.');

            return self::FAILURE;
        }

        $tenant = trim((string) ($input->getOption('tenant') ?? ''));
        if ($tenant === '') {
            /** @var StaleIntentSweeper $sweeper */
            $sweeper = app($context, StaleIntentSweeper::class);
        } else {
            // Deliberately NOT resolved from the container: the container's resolver is the
            // request-time one, and this run's tenant came from the operator, not a request.
            // The CONNECTION still comes from the container, so this repository provably reads
            // and writes the same database every other Payvia repository does.
            $connection = app($context, Connection::class);
            $sweeper = new StaleIntentSweeper(new PaymentIntentRepository(
                connection: $connection instanceof Connection ? $connection : null,
                resolver: new ExplicitTenantResolver($tenant),
            ));
        }

        $effectiveDays = $sweeper->staleAfterDays($context, $days);
        $swept = $sweeper->sweep($context, $effectiveDays, $limit);

        $this->info(sprintf(
            'Retired %d stale Payvia payment intent(s) untouched for more than %d day(s)%s.',
            $swept,
            $effectiveDays,
            $tenant === '' ? '' : " for tenant {$tenant}"
        ));

        return self::SUCCESS;
    }

    /**
     * Read an optional integer option STRICTLY: absent -> `null`, an integer literal -> its value,
     * anything else -> {@see \InvalidArgumentException}. `'daily'`, `''`, `'12abc'`, `'1.5'` and
     * `'1e3'` are all rejected — `is_numeric()` would accept the last two and silently truncate,
     * and a bare `(int)` cast turns every one of them into 0.
     */
    private function integerOption(InputInterface $input, string $name): ?int
    {
        $value = $input->getOption($name);
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);
        if (preg_match('/^-?\d+$/', $raw) !== 1) {
            throw new \InvalidArgumentException(
                "The --{$name} option must be an integer; got '{$raw}'."
            );
        }

        return (int) $raw;
    }
}
