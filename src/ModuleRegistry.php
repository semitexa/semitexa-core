<?php

declare(strict_types=1);

namespace Semitexa\Core;

use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Core\Support\Str;

/**
 * Module Registry for managing different types of modules
 *
 * This class handles discovery and registration of:
 * - Local modules (src/modules/)
 * - Composer modules (src/packages/)
 * - Vendor modules (vendor/)
 */
class ModuleRegistry
{
    /**
     * @var list<array{
     *   path: string,
     *   name: string,
     *   type: string,
     *   namespace: string,
     *   composerType: ?string,
     *   aliases: list<string>,
     *   templatePaths: list<string>,
     *   extends: ?string,
     *   controllers: list<string>,
     *   routes: list<mixed>,
     *   autoloadPsr4: array<string, list<string>>,
     *   config: array<string, mixed>
     * }>
     */
    private array $modules = [];
    private bool $initialized = false;

    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->discoverModules();

        $this->initialized = true;
    }

    public function getModules(): array
    {
        $this->initialize();
        return $this->modules;
    }

    public function getModuleAutoloadMappings(): array
    {
        $this->initialize();

        $mappings = [];
        foreach ($this->modules as $module) {
            $psr4 = $module['autoloadPsr4'];
            foreach ($psr4 as $prefix => $dirs) {
                foreach ($dirs as $dir) {
                    $mappings[$prefix][] = $dir;
                }
            }
        }

        foreach ($mappings as $prefix => $dirs) {
            $mappings[$prefix] = array_values(array_unique($dirs));
        }

        return $mappings;
    }

    public function getModulesByType(string $type): array
    {
        $this->initialize();
        return array_filter($this->modules, fn($module) => $module['type'] === $type);
    }

    public function getLocalModules(): array
    {
        return $this->getModulesByType('local');
    }

    public function getComposerModules(): array
    {
        return $this->getModulesByType('composer');
    }

    public function getVendorModules(): array
    {
        return $this->getModulesByType('vendor');
    }

    public function isActive(string $moduleName): bool
    {
        $this->initialize();

        foreach ($this->modules as $module) {
            if ($module['name'] === $moduleName || in_array($moduleName, $module['aliases'], true)) {
                return (bool)($module['config']['active'] ?? true);
            }
        }
        return false;
    }

    public function getModuleNameForClass(string $className): ?string
    {
        $this->initialize();
        $className = ltrim($className, '\\');
        foreach ($this->modules as $module) {
            $ns = $module['namespace'];
            if ($ns !== '' && str_starts_with($className, $ns . '\\')) {
                return $module['name'];
            }
        }
        return null;
    }

    public function isClassActive(string $className): bool
    {
        $moduleName = $this->getModuleNameForClass($className);
        if ($moduleName === null) {
            return true;
        }
        return $this->isActive($moduleName);
    }

    /**
     * @return list<string>
     */
    public function getModuleOrderByExtends(): array
    {
        $this->initialize();
        $names = array_keys(array_column($this->modules, null, 'name'));
        $edges = [];
        foreach ($this->modules as $module) {
            $parent = $module['extends'] ?? null;
            if ($parent !== null && $parent !== '') {
                $edges[$module['name']] = $parent;
            }
        }
        $inDegree = array_fill_keys($names, 0);
        foreach ($edges as $child => $parent) {
            if (isset($inDegree[$parent])) {
                $inDegree[$parent]++;
            }
        }
        $queue = array_keys(array_filter($inDegree, fn(int $d): bool => $d === 0));
        $order = [];
        while ($queue !== []) {
            $n = array_shift($queue);
            $order[] = $n;
            foreach ($edges as $c => $p) {
                if ($c === $n && isset($inDegree[$p])) {
                    $inDegree[$p]--;
                    if ($inDegree[$p] === 0) {
                        $queue[] = $p;
                    }
                }
            }
        }
        return $order;
    }

    private function discoverModules(): void
    {
        $projectRoot = ProjectRoot::get();

        foreach ($this->discoverLocalModules($projectRoot) as $module) {
            $this->registerModule($module['path'], $module['name'], 'local', $module['namespace']);
        }

        foreach ($this->discoverPackageModules($projectRoot) as $module) {
            $this->registerModule($module['path'], $module['name'], 'composer', $module['namespace']);
        }

        foreach ($this->discoverVendorModules($projectRoot) as $module) {
            $this->registerModule($module['path'], $module['name'], 'vendor', $module['namespace']);
        }
    }

    /**
     * @return list<array{path: string, name: string, namespace: string}>
     */
    private function discoverLocalModules(string $projectRoot): array
    {
        $modules = [];
        $modulesPath = $projectRoot . '/src/modules';

        if (!is_dir($modulesPath)) {
            return $modules;
        }

        $directories = $this->globDirectories($modulesPath . '/*');

        foreach ($directories as $dir) {
            $moduleName = basename($dir);
            $namespace = 'Semitexa\\Modules\\' . Str::toStudly($moduleName);

            $modules[] = [
                'path' => $dir,
                'name' => $moduleName,
                'namespace' => $namespace
            ];
        }

        return $modules;
    }

    /**
     * @return list<array{path: string, name: string, namespace: string}>
     */
    private function discoverVendorModules(string $projectRoot): array
    {
        return $this->discoverModulesInRoot($projectRoot . '/vendor');
    }

    private function registerModule(string $path, string $name, string $type, string $namespace): void
    {
        $composerType = $this->readComposerType($path);
        if ($type !== 'local' && !in_array($composerType, ['semitexa-module', 'semitexa-theme'], true)) {
            return;
        }

        $meta = $this->readComposerMeta($path);

        $defaultTemplatePath = $path . '/src/Application/View/templates';
        if (!is_dir($defaultTemplatePath)) {
            $defaultTemplatePath = $path . '/Application/View/templates';
        }

        $aliases = [];
        $aliases[] = $name;
        $friendly = $name;
        foreach (["semitexa-module-", "module-", "semitexa-"] as $prefix) {
            if (str_starts_with($friendly, $prefix)) {
                $friendly = substr($friendly, strlen($prefix));
                break;
            }
        }
        if ($friendly !== $name) {
            $aliases[] = $friendly;
        }
        if (!empty($meta['template_alias'])) {
            $aliases[] = (string)$meta['template_alias'];
        }
        $aliases = array_values(array_unique($aliases));

        $templatePaths = [];
        if (is_dir($defaultTemplatePath)) {
            $templatePaths[] = $defaultTemplatePath;
        }
        foreach ($meta['template_paths'] as $rel) {
            $p = $path . '/' . ltrim($rel, '/');
            if (is_dir($p)) {
                $templatePaths[] = $p;
            }
        }

        $realPath = realpath($path) ?: $path;
        foreach ($this->modules as $existing) {
            $existingRealPath = realpath($existing['path']) ?: $existing['path'];
            if ($existingRealPath === $realPath) {
                return;
            }
        }

        $this->modules[] = [
            'path' => $realPath,
            'name' => $name,
            'type' => $type,
            'namespace' => $namespace,
            'composerType' => $composerType,
            'aliases' => $aliases,
            'templatePaths' => $templatePaths,
            'extends' => $meta['extends'],
            'controllers' => $this->findControllers($path, $namespace),
            'routes' => $this->findRoutes($path, $namespace),
            'autoloadPsr4' => $this->resolveAutoloadPsr4($path, $meta['autoload_psr4']),
            'config' => $this->findModuleConfig($path, $namespace)
        ];
    }

    private function readComposerType(string $modulePath): ?string
    {
        $composerJson = $modulePath . '/composer.json';
        if (!is_file($composerJson)) {
            return null;
        }
        try {
            $json = json_decode((string)file_get_contents($composerJson), true, 512, JSON_THROW_ON_ERROR);
            return is_string($json['type'] ?? null) ? $json['type'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{
     *   template_alias: ?string,
     *   template_paths: list<string>,
     *   autoload_psr4: array<string, list<string>|string>,
     *   extends: ?string
     * }
     */
    private function readComposerMeta(string $modulePath): array
    {
        $meta = [
            'template_alias' => null,
            'template_paths' => [],
            'autoload_psr4' => [],
            'extends' => null,
        ];
        $composerJson = $modulePath . '/composer.json';
        if (!is_file($composerJson)) {
            return $meta;
        }
        try {
            $json = json_decode((string)file_get_contents($composerJson), true, 512, JSON_THROW_ON_ERROR);
            $extra = $json['extra']['semitexa-module'] ?? [];
            if (!empty($extra['template_alias']) && is_string($extra['template_alias'])) {
                $meta['template_alias'] = $extra['template_alias'];
            }
            if (!empty($extra['template_paths']) && is_array($extra['template_paths'])) {
                $meta['template_paths'] = array_values(array_filter(
                    $extra['template_paths'],
                    static fn (mixed $path): bool => is_string($path) && $path !== '',
                ));
            }
            if (isset($extra['extends']) && is_string($extra['extends']) && $extra['extends'] !== '') {
                $meta['extends'] = $extra['extends'];
            }
            if (!empty($json['autoload']['psr-4']) && is_array($json['autoload']['psr-4'])) {
                $autoloadPsr4 = [];
                foreach ($json['autoload']['psr-4'] as $prefix => $paths) {
                    if (!is_string($prefix)) {
                        continue;
                    }

                    if (is_string($paths)) {
                        $autoloadPsr4[$prefix] = $paths;
                        continue;
                    }

                    if (!is_array($paths)) {
                        continue;
                    }

                    $normalizedPaths = array_values(array_filter(
                        $paths,
                        static fn (mixed $path): bool => is_string($path),
                    ));

                    if ($normalizedPaths !== []) {
                        $autoloadPsr4[$prefix] = $normalizedPaths;
                    }
                }

                $meta['autoload_psr4'] = $autoloadPsr4;
            }
        } catch (\Throwable) {
            // ignore invalid json
        }
        return $meta;
    }

    /**
     * @param array<string, list<string>|string> $psr4
     * @return array<string, list<string>>
     */
    private function resolveAutoloadPsr4(string $modulePath, array $psr4): array
    {
        $resolved = [];
        foreach ($psr4 as $prefix => $paths) {
            $normalizedPrefix = $prefix === '' ? '' : rtrim($prefix, '\\') . '\\';
            $paths = is_array($paths) ? $paths : [$paths];
            foreach ($paths as $rel) {
                $full = $rel === ''
                    ? rtrim($modulePath, '/')
                    : rtrim($modulePath, '/') . '/' . ltrim($rel, '/');
                $full = realpath($full) ?: $full;
                if (is_dir($full)) {
                    $resolved[$normalizedPrefix][] = $full;
                }
            }
        }
        foreach ($resolved as $prefix => $dirs) {
            $resolved[$prefix] = array_values(array_unique($dirs));
        }
        return $resolved;
    }

    /**
     * @return list<string>
     */
    private function findControllers(string $path, string $namespace): array
    {
        $controllers = [];
        $files = $this->globPaths($path . '/*Controller.php');

        foreach ($files as $file) {
            $className = basename($file, '.php');
            $controllers[] = $namespace . '\\' . $className;
        }

        return $controllers;
    }

    /**
     * @return list<mixed>
     */
    private function findRoutes(string $path, string $namespace): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function findModuleConfig(string $path, string $namespace): array
    {
        return ['active' => true, 'role' => 'observer'];
    }

    /**
     * @return list<array{path: string, name: string, namespace: string}>
     */
    private function discoverPackageModules(string $projectRoot): array
    {
        return $this->discoverModulesInRoot($projectRoot . '/packages');
    }

    /**
     * @return list<array{path: string, name: string, namespace: string}>
     */
    private function discoverModulesInRoot(string $root): array
    {
        $modules = [];
        if (!is_dir($root)) {
            return $modules;
        }

        $dirs = $this->globDirectories(rtrim($root, '/') . '/*');
        foreach ($dirs as $dir) {
            if (is_file($dir . '/composer.json')) {
                $packageName = basename($dir);
                $namespace = $this->inferNamespaceFromComposer($dir)
                    ?? $this->buildNamespaceFromVendor('', $packageName);

                $modules[] = [
                    'path' => $dir,
                    'name' => $packageName,
                    'namespace' => $namespace
                ];
                continue;
            }

            $packageDirs = $this->globDirectories($dir . '/*');
            foreach ($packageDirs as $subDir) {
                $packageName = basename($subDir);
                $namespace = $this->inferNamespaceFromComposer($subDir)
                    ?? $this->buildNamespaceFromVendor(basename($dir), $packageName);

                $modules[] = [
                    'path' => $subDir,
                    'name' => $packageName,
                    'namespace' => $namespace
                ];
            }
        }

        return $modules;
    }

    private function inferNamespaceFromComposer(string $modulePath): ?string
    {
        $composerJson = $modulePath . '/composer.json';
        if (!is_file($composerJson)) {
            return null;
        }

        try {
            $json = json_decode((string)file_get_contents($composerJson), true, 512, JSON_THROW_ON_ERROR);
            $psr4 = $json['autoload']['psr-4'] ?? null;
            if (!is_array($psr4) || empty($psr4)) {
                return null;
            }
            foreach ($psr4 as $namespace => $_) {
                if (is_string($namespace) && $namespace !== '') {
                    return rtrim($namespace, '\\');
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function buildNamespaceFromVendor(string $vendor, string $package): string
    {
        $packageNamespace = Str::toStudly($package);
        if ($vendor === '') {
            return $packageNamespace;
        }

        return Str::toStudly($vendor) . '\\' . $packageNamespace;
    }

    /**
     * @return list<string>
     */
    private function globDirectories(string $pattern): array
    {
        return $this->globPaths($pattern, GLOB_ONLYDIR);
    }

    /**
     * @return list<string>
     */
    private function globPaths(string $pattern, int $flags = 0): array
    {
        $paths = glob($pattern, $flags);
        if ($paths === false) {
            throw new \RuntimeException(sprintf('Failed to scan filesystem pattern "%s".', $pattern));
        }

        return $paths;
    }
}
