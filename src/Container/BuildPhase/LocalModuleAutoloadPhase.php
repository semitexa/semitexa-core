<?php

declare(strict_types=1);

namespace Semitexa\Core\Container\BuildPhase;

use Semitexa\Core\Boot\LocalModuleAutoloadRegistrar;

/**
 * Registers PSR-4 mappings for local modules (src/modules/<Name>/src) on the
 * live Composer ClassLoader.
 *
 * Must run BEFORE ClassmapLoadPhase: ClassDiscovery's downstream phases call
 * class_exists() on every discovered class, and module classes will fail to
 * autoload unless their PSR-4 mapping is already registered.
 *
 * Preconditions: vendor/autoload.php has been loaded (which is guaranteed by
 * any Semitexa entry point — server.php / bin/semitexa / phpunit bootstrap).
 * Postconditions: `App\Modules\<Name>` and `Semitexa\Modules\<Name>` classes
 * for every local module are autoloadable.
 */
final class LocalModuleAutoloadPhase implements BuildPhaseInterface
{
    public function execute(BuildContext $context): void
    {
        LocalModuleAutoloadRegistrar::register();
    }

    public function name(): string
    {
        return 'LocalModuleAutoload';
    }
}
