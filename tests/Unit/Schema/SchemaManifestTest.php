<?php

declare(strict_types=1);

namespace Glueful\Extensions\Payvia\Tests\Unit\Schema;

use Glueful\Extensions\Payvia\Schema\PayviaSchemaVerifier;
use PHPUnit\Framework\TestCase;

final class SchemaManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true);
        return $composer['extra']['glueful'];
    }

    public function testDeclaresExactlyOneDefaultDependentOnEnableDescriptor(): void
    {
        $migrations = $this->manifest()['migrations'];
        self::assertCount(1, $migrations);
        self::assertSame('default', $migrations[0]['id']);
        self::assertSame('migrations', $migrations[0]['path']);
        self::assertSame('dependent', $migrations[0]['priority']);
        self::assertSame('on_enable', $migrations[0]['mode']);
        self::assertSame('>=1.79.0', $this->manifest()['requires']['glueful']);
        self::assertSame([], $this->manifest()['requires']['extensions']);
    }

    public function testVerifierClassConformsToTheManifestContract(): void
    {
        $class = $this->manifest()['migrations'][0]['verifier'];
        self::assertTrue(class_exists($class));
        self::assertTrue(is_subclass_of($class, \Glueful\Extensions\Schema\StructuralVerifierInterface::class));
        $constructor = (new \ReflectionClass($class))->getConstructor();
        self::assertTrue($constructor === null || $constructor->getNumberOfRequiredParameters() === 0);
        self::assertSame('glueful/payvia', (new $class())->source());
    }

    public function testVerifierCoversEveryRecursivelyDiscoveredMigration(): void
    {
        $mapped = (new PayviaSchemaVerifier())->migrationBasenames();
        $root = dirname(__DIR__, 3) . '/migrations';
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        $shipped = [];
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $shipped[] = $file->getBasename();
            }
        }
        sort($shipped);
        sort($mapped);
        self::assertSame($shipped, $mapped, 'every migration file needs a verifier proof');
    }

    public function testResolvesWhenEnabledAloneAtItsDeclaredFloor(): void
    {
        $g = $this->manifest();
        $candidates = ['glueful/payvia' => new \Glueful\Extensions\ExtensionCandidate(
            name: 'glueful/payvia',
            provider: $g['provider'],
            requiresGlueful: $g['requires']['glueful'],
            requiresExtensions: $g['requires']['extensions'],
        )];
        $result = (new \Glueful\Extensions\ExtensionResolver())
            ->resolve($candidates, [$g['provider']], '1.79.0');
        self::assertSame([], $result->errors, 'payvia must resolve enabled-alone');
    }

    public function testProviderNoLongerRegistersTheManifestOwnedPath(): void
    {
        $provider = $this->manifest()['provider'];
        $file = (new \ReflectionClass($provider))->getFileName();
        self::assertIsString($file);
        self::assertStringNotContainsString('loadMigrationsFrom(', (string) file_get_contents($file));
    }
}
