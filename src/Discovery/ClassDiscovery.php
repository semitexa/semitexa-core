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
        $composerPsr4Map = require $composerDir . '/autoload_psr4.php';

        // Refresh the Composer ClassLoader with the current classmap and PSR-4 namespaces.
        // Needed after graceful Swoole reload: workers inherit the master's stale ClassLoader,
        // so newly-installed packages fail to autoload at request time.
        self::refreshComposerAutoloader($composerDir, $composerClassMap);

        foreach ($composerClassMap as $className => $filePath) {
            if (self::isNamespaceAllowed($className)) {
                self::$classMap[$className] = $filePath;
            }
        }

        self::mergePsr4ClassCandidates($composerPsr4Map);

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

            try {
                $exists = class_exists($className, true) || interface_exists($className, true) || trait_exists($className, true);
            } catch (\Throwable $e) {
                // Class file loaded but references a missing dependency (e.g. a dev-only
                // interface like PHPUnit's in production). Skip it — it cannot be used.
                if (Environment::getEnvValue('APP_DEBUG') === '1') {
                    error_log("[Semitexa] ClassDiscovery: skipping {$className} (load error): " . $e->getMessage());
                }
                continue;
            }

            if (!$exists) {
                // class_exists with autoload failed — the class may have been found via
                // PSR-4 directory scan but is absent from Composer's generated classmap.
                // Load it directly with require_once (idempotent: won't double-include).
                $filePath = self::$classMap[$className] ?? null;
                if (is_string($filePath) && is_file($filePath)) {
                    try {
                        (static function (string $f): void { require_once $f; })($filePath);
                    } catch (\Throwable $e) {
                        if (Environment::getEnvValue('APP_DEBUG') === '1') {
                            error_log("[Semitexa] ClassDiscovery: require_once failed for {$className} ({$filePath}): " . $e->getMessage());
                        }
                    }
                }
                if (!class_exists($className, false) && !interface_exists($className, false) && !trait_exists($className, false)) {
                    continue;
                }
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

    /**
     * Composer's generated classmap can lag behind local PSR-4 edits until
     * dump-autoload runs. Merge PSR-4-derived class candidates so attribute
     * discovery sees newly-added or renamed classes immediately.
     *
     * @param array<string, list<string>|string> $psr4Map
     */
    private static function mergePsr4ClassCandidates(array $psr4Map): void
    {
        uksort($psr4Map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $seenRealPaths = [];
        foreach ($psr4Map as $namespace => $dirs) {
            if (!self::isNamespaceAllowed($namespace)) {
                continue;
            }

            foreach ((array) $dirs as $dir) {
                if (!self::shouldMergePsr4Directory($dir)) {
                    continue;
                }

                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(
                        $dir,
                        \FilesystemIterator::SKIP_DOTS,
                    ),
                );

                foreach ($iterator as $fileInfo) {
                    if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                        continue;
                    }

                    $realPath = $fileInfo->getRealPath();
                    if ($realPath !== false && isset($seenRealPaths[$realPath])) {
                        continue;
                    }

                    $className = self::extractDeclaredClassName($fileInfo->getPathname());
                    if ($className === null || !self::isNamespaceAllowed($className)) {
                        continue;
                    }

                    if (!isset(self::$classMap[$className])) {
                        self::$classMap[$className] = $fileInfo->getPathname();
                        if ($realPath !== false) {
                            $seenRealPaths[$realPath] = true;
                        }
                    }
                }
            }
        }
    }

    private static function shouldMergePsr4Directory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $projectRoot = ProjectRoot::get();
        $vendorRoot = $projectRoot . '/vendor/';
        $realPath = realpath($dir);
        if ($realPath === false) {
            return false;
        }

        // Keep fallback scanning limited to live project code, Semitexa vendor
        // packages, and path repositories (e.g. vendor symlinks into packages/*).
        // Non-Semitexa vendor packages should still rely on Composer's classmap to
        // avoid a full filesystem walk on every bootstrap.
        if (($realPath === $projectRoot . '/src' || str_starts_with($realPath, $projectRoot . '/src/'))
            || ($realPath === $projectRoot . '/tests' || str_starts_with($realPath, $projectRoot . '/tests/'))
            || ($realPath === $projectRoot . '/packages' || str_starts_with($realPath, $projectRoot . '/packages/'))
        ) {
            return true;
        }

        if (($realPath === $projectRoot . '/vendor/semitexa' || str_starts_with($realPath, $projectRoot . '/vendor/semitexa/'))
            && str_starts_with($dir, $vendorRoot)
        ) {
            return true;
        }

        return str_starts_with($dir, $vendorRoot) && !str_starts_with($realPath, $vendorRoot);
    }

    private static function extractDeclaredClassName(string $filePath): ?string
    {
        $source = @file_get_contents($filePath);
        if ($source === false) {
            return null;
        }

        $tokens = token_get_all($source);
        $namespace = '';
        $collectNamespace = false;
        $collectClass = false;
        /** @var int|string|null $previousSignificantToken */
        $previousSignificantToken = null;

        foreach ($tokens as $token) {
            if (!is_array($token)) {
                if ($collectNamespace && ($token === ';' || $token === '{')) {
                    $collectNamespace = false;
                }
                if (trim($token) !== '') {
                    $previousSignificantToken = $token;
                }
                continue;
            }

            [$id, $text] = $token;

            if ($id === T_NAMESPACE) {
                $namespace = '';
                $collectNamespace = true;
                continue;
            }

            if ($collectNamespace) {
                if ($id === T_STRING || $id === T_NAME_QUALIFIED || $id === T_NS_SEPARATOR) {
                    $namespace .= $text;
                }
                continue;
            }

            if ($id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT || $id === T_ENUM) {
                if ($previousSignificantToken === T_DOUBLE_COLON) {
                    continue;
                }
                if ($id === T_CLASS && $previousSignificantToken === T_NEW) {
                    continue;
                }
                $collectClass = true;
                $previousSignificantToken = $id;
                continue;
            }

            if ($collectClass && $text === '{') {
                $collectClass = false;
                continue;
            }

            if ($collectClass && $id === T_STRING) {
                return $namespace !== '' ? $namespace . '\\' . $text : $text;
            }

            if (!in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $previousSignificantToken = $id;
            }
        }

        return null;
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
