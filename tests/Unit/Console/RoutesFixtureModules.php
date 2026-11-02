<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Console;

use Semitexa\Core\ModuleRegistry;

/** Classes of the routes fixture belong to the module Shop; anything else to none. */
final class RoutesFixtureModules extends ModuleRegistry
{
    public function __construct()
    {
    }

    public function getModuleNameForClass(string $className): ?string
    {
        return str_starts_with($className, __NAMESPACE__ . '\\RoutesFixture') ? 'Shop' : null;
    }
}
