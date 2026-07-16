<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Payvia\Tenancy\TenantAdopter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'payvia:tenancy:adopt', description: 'Adopt sentinel Payvia rows into a tenant')]
final class TenancyAdoptCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant uuid to adopt sentinel rows into');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tenant = (string) $input->getOption('tenant');
        if (trim($tenant) === '') {
            $this->error('The --tenant option is required.');

            return self::FAILURE;
        }

        try {
            $result = app($this->getContext(), TenantAdopter::class)->adopt($this->getContext(), $tenant);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $rows = [];
        foreach ($result['tables'] as $table => $count) {
            $rows[] = [$table, (string) $count];
        }

        $this->info("Adopted sentinel Payvia rows into tenant {$result['tenant_uuid']}.");
        $this->table(['Table', 'Rows'], $rows);

        return self::SUCCESS;
    }
}
