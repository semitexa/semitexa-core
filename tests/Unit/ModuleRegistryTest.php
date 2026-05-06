<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Support\ProjectRoot;

final class ModuleRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        ProjectRoot::reset();
    }

    public function test_get_modules_initializes_registry_on_first_access(): void
    {
        $registry = new ModuleRegistry();
        $modules = $registry->getModules();

        self::assertNotEmpty($modules, 'registry should discover at least one module after initialization');

        $names = array_column($modules, 'name');
        self::assertCount(
            count($modules),
            $names,
            'every module entry must expose a name field (array_column drops entries missing the key)',
        );
        foreach ($names as $name) {
            self::assertIsString($name);
            self::assertNotSame('', trim($name), 'module names must be non-empty strings');
        }

        $types = array_column($modules, 'type');
        foreach ($types as $type) {
            self::assertContains($type, ['local', 'composer', 'vendor'], "unexpected module type: {$type}");
        }
    }
}
