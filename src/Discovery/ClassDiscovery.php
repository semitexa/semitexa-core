<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

use Semitexa\Core\Support\ProjectRoot;

class ClassDiscovery
{
    private array $classMap = [];
    private bool $initialized = false;
    private array $attributeCache = [];

    private array $allowedNamespacePrefixes = [
        'Semitexa\\' => true,
        'App\\' => true,
    ];

    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $composerDir = ProjectRoot::get() . '/vendor/composer';
        $composerClassMap = require $composerDir . '/autoload_classmap.php';
        $composerPsr4Map = require $composerDir . '/autoload_psr4.php';

        $this->refreshComposerAutoloader($composerDir, $composerClassMap);

        foreach ($composerClassMap as $className => $filePath) {
            if ($this->isNamespaceAllowed($className)) {
                $this->classMap[$className] = $filePath;
            }
        }

        $this->mergePsr4ClassCandidates($composerPsr4Map);

        $this->initialized = true;
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
            if (str_starts_with($className, 'Semitexa\\Core\\Composer\\')
                || str_starts_with($className, 'App\\Tests\\')
            ) {
                continue;
            }

            try {
                $exists = class_exists($className, true) || interface_exists($className, true) || trait_exists($className, true);
            } catch (\Throwable $e) {
                BootDiagnostics::current()->skip('ClassDiscovery', "Skipping {$className} (load error): " . $e->getMessage(), $e);
                continue;
            }

            if (!$exists) {
                $filePath = $this->classMap[$className] ?? null;
                if (is_string($filePath) && is_file($filePath)) {
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

        $this->attributeCache[$attributeClass] = $classes;

        return $classes;
    }

    public function getClassMap(): array
    {
        $this->initialize();

        return $this->classMap;
    }

    private function refreshComposerAutoloader(string $composerDir, array $freshClassMap): void
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
                    if ($className === null || !$this->isNamespaceAllowed($className)) {
                        continue;
                    }

                    if (!isset($this->classMap[$className])) {
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
}
