<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Payvia\Services\StaleIntentSweeper;
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
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->getContext();
        /** @var StaleIntentSweeper $sweeper */
        $sweeper = app($context, StaleIntentSweeper::class);

        $override = $input->getOption('stale-after-days');
        $days = $sweeper->staleAfterDays($context, $override === null ? null : (int) $override);
        $swept = $sweeper->sweep($context, $days, (int) $input->getOption('limit'));

        $this->info(sprintf(
            'Retired %d stale Payvia payment intent(s) untouched for more than %d day(s).',
            $swept,
            $days
        ));

        return self::SUCCESS;
    }
}
