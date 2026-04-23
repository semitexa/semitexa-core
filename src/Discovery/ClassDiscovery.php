<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

use Semitexa\Core\Support\ProjectRoot;

class ClassDiscovery
{
    /** @var array<class-string, string> */
    private array $classMap = [];
    private bool $initialized = false;
    /** @var array<class-string, list<class-string>> */
    private array $attributeCache = [];

    private array $allowedNamespacePrefixes = [
        'Semitexa\\' => true,
        'App\\' => true,
    ];

    /**
     * FQCN substrings that mark a class as dev-only and therefore excluded from the
     * runtime classmap. These classes typically extend interfaces that ship in
     * require-dev dependencies (phpstan/phpstan, phpunit/phpunit), which are absent
     * in consumer projects — scanning them triggers autoload errors and floods the
     * boot log with [skip] warnings. Filtering at classmap-population time keeps
     * them out of every downstream iteration.
     */
    private const RUNTIME_EXCLUDE_SUBSTRINGS = [
        '\\PHPStan\\',
        '\\Composer\\',
        '\\Tests\\',
        '\\Testing\\PhpUnit',
    ];

    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $composerDir = ProjectRoot::get() . '/vendor/composer';
        $composerClassMap = $this->loadComposerClassMap($composerDir . '/autoload_classmap.php');
        $composerPsr4Map = $this->loadComposerPsr4Map($composerDir . '/autoload_psr4.php');

        $composerClassMap = array_filter(
            $composerClassMap,
            static fn (string $filePath): bool => is_file($filePath),
        );

        $this->refreshComposerAutoloader($composerDir, $composerClassMap);

        foreach ($composerClassMap as $className => $filePath) {
            if ($this->isNamespaceAllowed($className) && !$this->isRuntimeExcluded($className)) {
                $this->classMap[$className] = $filePath;
            }
        }

        $this->mergePsr4ClassCandidates($composerPsr4Map);

        $this->initialized = true;
    }

    /**
     * @return array<class-string, string>
     */
    private function loadComposerClassMap(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $loaded = require $path;
        if (!is_array($loaded)) {
            return [];
        }

        $classMap = [];
        foreach ($loaded as $className => $filePath) {
            if (is_string($className) && is_string($filePath)) {
                /** @var class-string $className */
                $classMap[$className] = $filePath;
            }
        }

        return $classMap;
    }

    /**
     * @return array<string, list<string>|string>
     */
    private function loadComposerPsr4Map(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $loaded = require $path;
        if (!is_array($loaded)) {
            return [];
        }

        $psr4Map = [];
        foreach ($loaded as $namespace => $dirs) {
            if (!is_string($namespace)) {
                continue;
            }

            if (is_string($dirs)) {
                $psr4Map[$namespace] = $dirs;
                continue;
            }

            if (!is_array($dirs)) {
                continue;
            }

            $normalizedDirs = array_values(array_filter($dirs, static fn (mixed $dir): bool => is_string($dir)));
            $psr4Map[$namespace] = $normalizedDirs;
        }

        return $psr4Map;
    }

    /**
     * @return list<string>
     */
    public function findClassesWithAttribute(string $attributeClass): array
    {
        if (isset($this->attributeCache[$attributeClass])) {
            return $this->attributeCache[$attributeClass];
        }

        $this->initialize();

        $classes = [];

        foreach ($this->classMap as $className => $filePath) {
            try {
                $exists = class_exists($className, true) || interface_exists($className, true) || trait_exists($className, true);
            } catch (\Throwable $e) {
                BootDiagnostics::current()->skip('ClassDiscovery', "Skipping {$className} (load error): " . $e->getMessage(), $e);
                continue;
            }

            if (!$exists) {
                $filePath = $this->classMap[$className];
                if (is_file($filePath)) {
                    try {
                        (static function (string $f): void { require_once $f; })($filePath);
                    } catch (\Throwable $e) {
                        BootDiagnostics::current()->skip('ClassDiscovery', "require_once failed for {$className} ({$filePath}): " . $e->getMessage(), $e);
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
                BootDiagnostics::current()->skip('ClassDiscovery', "findClassesWithAttribute({$attributeClass}) failed for {$className}: " . $e->getMessage(), $e);
            }
        }

        /** @var class-string $attributeClass */
        $this->attributeCache[$attributeClass] = $classes;

        return $classes;
    }

    /**
     * @return array<class-string, string>
     */
    public function getClassMap(): array
    {
        $this->initialize();

        return $this->classMap;
    }

    /**
     * @param array<class-string, string> $freshClassMap
     */
    private function refreshComposerAutoloader(string $composerDir, array $freshClassMap): void
    {
        try {
            $psr4File = $composerDir . '/autoload_psr4.php';
            /** @var array<string, list<string>|string> $freshPsr4 */
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
     * @param array<string, list<string>|string> $psr4Map
     */
    private function mergePsr4ClassCandidates(array $psr4Map): void
    {
        uksort($psr4Map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $seenRealPaths = [];
        foreach ($psr4Map as $namespace => $dirs) {
            if (!$this->isNamespaceAllowed($namespace)) {
                continue;
            }

            foreach ((array) $dirs as $dir) {
                if (!$this->shouldMergePsr4Directory($dir)) {
                    continue;
                }

                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(
                        $dir,
                        \FilesystemIterator::SKIP_DOTS,
                    ),
                    \RecursiveIteratorIterator::LEAVES_ONLY,
                    \RecursiveIteratorIterator::CATCH_GET_CHILD,
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
                    if ($className === null
                        || !$this->isNamespaceAllowed($className)
                        || $this->isRuntimeExcluded($className)
                    ) {
                        continue;
                    }

                    if (!isset($this->classMap[$className])) {
                        /** @var class-string $className */
                        $this->classMap[$className] = $fileInfo->getPathname();
                        if ($realPath !== false) {
                            $seenRealPaths[$realPath] = true;
                        }
                    }
                }
            }
        }
    }

    private function shouldMergePsr4Directory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $projectRoot = ProjectRoot::get();
        $projectRootReal = realpath($projectRoot) ?: $projectRoot;
        $vendorRoot = $projectRoot . '/vendor/';
        $vendorRootReal = $projectRootReal . '/vendor/';
        $realPath = realpath($dir);
        if ($realPath === false) {
            return false;
        }

        if (($realPath === $projectRootReal . '/src' || str_starts_with($realPath, $projectRootReal . '/src/'))
            || ($realPath === $projectRootReal . '/tests' || str_starts_with($realPath, $projectRootReal . '/tests/'))
            || ($realPath === $projectRootReal . '/packages' || str_starts_with($realPath, $projectRootReal . '/packages/'))
        ) {
            return true;
        }

        if (($realPath === $projectRootReal . '/vendor/semitexa' || str_starts_with($realPath, $projectRootReal . '/vendor/semitexa/'))
            && str_starts_with($dir, $vendorRoot)
        ) {
            return true;
        }

        return str_starts_with($dir, $vendorRoot) && !str_starts_with($realPath, $vendorRootReal);
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

    private function isNamespaceAllowed(string $className): bool
    {
        foreach (array_keys($this->allowedNamespacePrefixes) as $prefix) {
            if (str_starts_with($className, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function isRuntimeExcluded(string $className): bool
    {
        foreach (self::RUNTIME_EXCLUDE_SUBSTRINGS as $needle) {
            if (str_contains($className, $needle)) {
                return true;
            }
        }
        return false;
    }
}
