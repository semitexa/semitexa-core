<?php

declare(strict_types=1);

namespace Semitexa\Core\Boot;

use Composer\Autoload\ClassLoader;
use Semitexa\Core\Support\ProjectRoot;

/**
 * Registers PSR-4 autoload mappings for local modules under src/modules/<Name>/src.
 *
 * Local modules live at:
 *   src/modules/<Name>/src        (runtime PHP)
 *   src/modules/<Name>/tests      (test PHP — handled by the test runner, not here)
 *
 * Composer's static PSR-4 cannot express a wildcard like `src/modules/*\/src`,
 * and the project's `App\` => `src/` mapping does not cover module class loading
 * because the `App\Modules\<Name>` and `Semitexa\Modules\<Name>` namespaces both
 * need to resolve to `src/modules/<Name>/src/`.
 *
 * This registrar scans the modules directory at boot time and registers the
 * mappings on the live Composer ClassLoader. It is invoked from a dedicated
 * BuildPhase so runtime module loading is owned by the bootloader, not by an
 * ad-hoc composer autoload-files file at the project src root.
 *
 * Test namespaces (`App\Tests\Modules\<Name>`) are intentionally NOT registered
 * here — that lives in the test runner's bootstrap to keep production runtime
 * free of test mappings.
 *
 * Idempotent: addPsr4 merges directories; calling register() twice with the
 * same input produces the same final state.
 */
final class LocalModuleAutoloadRegistrar
{
    public static function register(?string $projectRoot = null): void
    {
        $root = $projectRoot ?? ProjectRoot::get();
        $modulesDir = $root . '/src/modules';
        if (!is_dir($modulesDir)) {
            return;
        }

        $classLoaders = self::findComposerClassLoaders();
        if ($classLoaders === []) {
            return;
        }

        $entries = scandir($modulesDir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $srcDir = $modulesDir . '/' . $entry . '/src';
            if (!is_dir($srcDir)) {
                continue;
            }
            foreach ($classLoaders as $classLoader) {
                $classLoader->addPsr4("App\\Modules\\{$entry}\\", $srcDir);
                $classLoader->addPsr4("Semitexa\\Modules\\{$entry}\\", $srcDir);
            }
        }
    }

    /**
     * Returns every Composer ClassLoader currently registered with spl_autoload.
     *
     * Tools like PHPStan run the project's vendor/autoload.php on top of their
     * own bundled ClassLoader, so two (or more) loaders can coexist. The
     * project's loader resolves project classes; the tool's loader resolves the
     * tool's own dependencies. Registering on every loader makes the mappings
     * available regardless of which one is queried first.
     *
     * @return list<ClassLoader>
     */
    private static function findComposerClassLoaders(): array
    {
        $loaders = [];
        foreach (spl_autoload_functions() ?: [] as $autoloader) {
            if (is_array($autoloader) && ($autoloader[0] ?? null) instanceof ClassLoader) {
                $loaders[] = $autoloader[0];
            }
        }
        return $loaders;
    }
}
