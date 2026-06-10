<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

abstract class PayviaTestCase extends TestCase
{
    protected ApplicationContext $context;
    protected Connection $connection;

    /** @var array<string,mixed> */
    protected array $bindings = [];

    /** @var array<string,mixed> */
    protected array $config = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);

        $connection = $this->connection;
        $bindings = &$this->bindings;
        $config = &$this->config;

        $container = new class ($connection, $bindings, $config) implements ContainerInterface {
            /**
             * @param array<string,mixed> $bindings
             * @param array<string,mixed> $config
             */
            public function __construct(
                private Connection $connection,
                private array &$bindings,
                private array &$config,
            ) {
            }

            public function get(string $id): mixed
            {
                if ($id === 'database' || $id === Connection::class) {
                    return $this->connection;
                }

                if ($id === 'config') {
                    return $this->config;
                }

                if (array_key_exists($id, $this->bindings)) {
                    return $this->bindings[$id];
                }

                throw new \RuntimeException("Unknown service: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === 'database'
                    || $id === Connection::class
                    || $id === 'config'
                    || array_key_exists($id, $this->bindings);
            }
        };

        $this->context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $this->context->setContainer($container);
        $this->config = require __DIR__ . '/../../config/payvia.php';
    }

    protected function bind(string $id, mixed $service): void
    {
        $this->bindings[$id] = $service;
    }

    protected function setConfig(string $key, mixed $value): void
    {
        $parts = explode('.', $key);
        $target = &$this->config;
        foreach ($parts as $part) {
            if (!isset($target[$part]) || !is_array($target[$part])) {
                $target[$part] = [];
            }
            $target = &$target[$part];
        }
        $target = $value;
    }

    protected function runMigration(object $migration): void
    {
        $migration->up($this->connection->getSchemaBuilder());
    }
}
