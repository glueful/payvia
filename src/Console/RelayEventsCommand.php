<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Payvia\Services\WebhookService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'payvia:relay-events', description: 'Relay pending Payvia provider events')]
final class RelayEventsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Maximum rows to relay', 100);
        $this->addOption('stale-seconds', null, InputOption::VALUE_OPTIONAL, 'Reclaim stale dispatching rows after N seconds', 300);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = $this->getContext();
        $service = app($context, WebhookService::class);
        $count = $service->relayPending((int) $input->getOption('limit'), (int) $input->getOption('stale-seconds'));
        $this->info("Relayed {$count} Payvia provider event(s).");

        return self::SUCCESS;
    }
}
