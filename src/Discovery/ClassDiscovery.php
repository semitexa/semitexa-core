<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

use Semitexa\Core\Environment;
use Semitexa\Core\Util\ProjectRoot;

class ClassDiscovery
{
    private static array $classMap = [];
    private static bool $initialized = false;
    private static array $attributeCache = [];

    private static array $allowedNamespacePrefixes = [
        'Semitexa\\' => true,
        'App\\' => true,
    ];

    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        $composerDir = ProjectRoot::get() . '/vendor/composer';
        $composerClassMap = require $composerDir . '/autoload_classmap.php';

        // Refresh the Composer ClassLoader with the current classmap and PSR-4 namespaces.
        // Needed after graceful Swoole reload: workers inherit the master's stale ClassLoader,
        // so newly-installed packages fail to autoload at request time.
        self::refreshComposerAutoloader($composerDir, $composerClassMap);

        foreach ($composerClassMap as $className => $filePath) {
            if (self::isNamespaceAllowed($className)) {
                self::$classMap[$className] = $filePath;
            }
        }

        self::$initialized = true;
    }

    /**
     * @return list<string>
     */
    public static function findClassesWithAttribute(string $attributeClass): array
    {
        if (isset(self::$attributeCache[$attributeClass])) {
            return self::$attributeCache[$attributeClass];
        }

        self::initialize();

        $classes = [];

        foreach (self::$classMap as $className => $filePath) {
            if (str_starts_with($className, 'Semitexa\\Core\\Composer\\')
                || str_starts_with($className, 'App\\Tests\\')
            ) {
                continue;
            }

            if (!class_exists($className, true) && !interface_exists($className, true) && !trait_exists($className, true)) {
                continue;
            }

            try {
                $reflection = new \ReflectionClass($className);
                $attrs = $reflection->getAttributes($attributeClass);

                if ($attrs) {
                    $classes[] = $className;
                }
            } catch (\Throwable $e) {
                if (Environment::getEnvValue('APP_DEBUG') === '1') {
                    error_log("[Semitexa] ClassDiscovery::findClassesWithAttribute({$attributeClass}) failed for {$className}: " . $e->getMessage());
                }
            }
        }

        self::$attributeCache[$attributeClass] = $classes;

        return $classes;
    }

    public static function getClassMap(): array
    {
        self::initialize();

        return self::$classMap;
    }

    /**
     * Inject the current classmap and PSR-4 namespace map into the Composer ClassLoader.
     * This is a no-op on first boot (the ClassLoader is already current), but after a
     * Swoole graceful reload the master's forked workers inherit a stale ClassLoader —
     * calling this at ClassDiscovery::initialize() time ensures every newly-installed
     * package is autoloadable without a full server restart.
     */
    private static function refreshComposerAutoloader(string $composerDir, array $freshClassMap): void
    {
        try {
            $psr4File = $composerDir . '/autoload_psr4.php';
            $freshPsr4 = is_file($psr4File) ? (require $psr4File) : [];

            foreach (spl_autoload_functions() as $loader) {
                if (!is_array($loader) || !($loader[0] instanceof \Composer\Autoload\ClassLoader)) {
                    continue;
                }
                /** @var \Composer\Autoload\ClassLoader $classLoader */
                $classLoader = $loader[0];
                $classLoader->addClassMap($freshClassMap);
                foreach ($freshPsr4 as $namespace => $dirs) {
                    $classLoader->addPsr4($namespace, $dirs);
                }
                break;
            }
        } catch (\Throwable) {
            // Autoloader refresh is best-effort; never block initialization.
        }
    }

    private static function isNamespaceAllowed(string $className): bool
    {
        foreach (array_keys(self::$allowedNamespacePrefixes) as $prefix) {
            if (str_starts_with($className, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
