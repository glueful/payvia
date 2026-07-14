<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Payvia\Support\DiagnosticsReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'payvia:diagnose', description: 'Diagnose Payvia extension bindings and tenancy state')]
final class DiagnoseCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = DiagnosticsReport::build($this->getContext());

        $this->line('<info>Payvia contracts</info>');
        $rows = [];
        foreach ($report['contracts'] as $name => $binding) {
            $rows[] = [$name, $binding['source'], $binding['class'] ?? 'none'];
        }
        $this->table(['Contract', 'Source', 'Class'], $rows);

        $this->line('');
        $this->line('Resolver mode: ' . $report['tenancy']['resolver_mode']);
        $this->line('Registered tables: ' . implode(', ', $report['tenancy']['registered_tables']));

        $this->line('');
        $this->line('<info>Sentinel rows per table</info>');
        $sentinelRows = [];
        foreach ($report['tenancy']['sentinel_rows'] as $table => $count) {
            $sentinelRows[] = [$table, (string) $count];
        }
        $this->table(['Table', 'Sentinel rows'], $sentinelRows);

        $this->line('');
        $this->line(
            'Unresolved subscription-ownership failures: '
            . $report['webhooks']['unresolved_subscription_ownership_failures']
        );

        return self::SUCCESS;
    }
}
