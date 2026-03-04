<?php

declare(strict_types=1);

namespace Semitexa\Core;

use Semitexa\Core\Util\ProjectRoot;
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
    private static array $modules = [];
    private static bool $initialized = false;
    
    /**
     * Initialize the module registry
     */
    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }
        
        
        $startTime = microtime(true);
        self::discoverModules();
        $endTime = microtime(true);
        
        
        self::$initialized = true;
    }
    
    /**
     * Get all discovered modules
     */
    public static function getModules(): array
    {
        return self::$modules;
    }

    /**
     * Get combined PSR-4 autoload mappings for all modules
     * (namespace prefix => [absolute directories])
     */
    public static function getModuleAutoloadMappings(): array
    {
        self::initialize();
        
        $mappings = [];
        foreach (self::$modules as $module) {
            $psr4 = $module['autoloadPsr4'] ?? [];
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
    
    /**
     * Get modules by type
     */
    public static function getModulesByType(string $type): array
    {
        return array_filter(self::$modules, fn($module) => $module['type'] === $type);
    }
    
    /**
     * Get local modules
     */
    public static function getLocalModules(): array
    {
        return self::getModulesByType('local');
    }
    
    /**
     * Get composer modules
     */
    public static function getComposerModules(): array
    {
        return self::getModulesByType('composer');
    }
    
    /**
     * Get vendor modules
     */
    public static function getVendorModules(): array
    {
        return self::getModulesByType('vendor');
    }

    /**
     * Check if module is active
     */
    public static function isActive(string $moduleName): bool
    {
        foreach (self::$modules as $module) {
            if ($module['name'] === $moduleName || in_array($moduleName, $module['aliases'], true)) {
                return (bool)($module['config']['active'] ?? true);
            }
        }
        return false;
    }

    /**
     * Return module name (e.g. "Website") for a class in that module's namespace, or null.
     */
    public static function getModuleNameForClass(string $className): ?string
    {
        self::initialize();
        $className = ltrim($className, '\\');
        foreach (self::$modules as $module) {
            $ns = ($module['namespace'] ?? '');
            if ($ns !== '' && str_starts_with($className, $ns . '\\')) {
                return $module['name'];
            }
        }
        return null;
    }

    /**
     * Module names ordered by "extends" (most derived first). Used for contract resolution priority.
     * If A extends B, A appears before B so that A's implementation wins over B's.
     *
     * @return list<string>
     */
    public static function getModuleOrderByExtends(): array
    {
        self::initialize();
        $names = array_keys(array_column(self::$modules, null, 'name'));
        $edges = [];
        foreach (self::$modules as $module) {
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

    /**
     * Discover all modules
     */
    private static function discoverModules(): void
    {
        $projectRoot = ProjectRoot::get();
        
        // Discover local modules
        foreach (self::discoverLocalModules($projectRoot) as $module) {
            self::registerModule($module['path'], $module['name'], 'local', $module['namespace']);
        }
        
        // Discover packages/ (all vendors)
        foreach (self::discoverPackageModules($projectRoot) as $module) {
            self::registerModule($module['path'], $module['name'], 'composer', $module['namespace']);
        }
        
        // Discover vendor/ (installed via Composer)
        foreach (self::discoverVendorModules($projectRoot) as $module) {
            self::registerModule($module['path'], $module['name'], 'vendor', $module['namespace']);
        }
    }
    
    /**
     * Discover local modules in src/modules/
     */
    private static function discoverLocalModules(string $projectRoot): array
    {
        $modules = [];
        $modulesPath = $projectRoot . '/src/modules';
        
        if (!is_dir($modulesPath)) {
            return $modules;
        }
        
        $directories = glob($modulesPath . '/*', GLOB_ONLYDIR);
        
        foreach ($directories as $dir) {
            $moduleName = basename($dir);
            $namespace = "Semitexa\\Modules\\" . ucfirst($moduleName);
            
            $modules[] = [
                'path' => $dir,
                'name' => $moduleName,
                'namespace' => $namespace
            ];
        }
        
        return $modules;
    }
    
    /**
     * Discover vendor modules (optional)
     */
    private static function discoverVendorModules(string $projectRoot): array
    {
        return self::discoverModulesInRoot($projectRoot . '/vendor');
    }
    
    /**
     * Register a module
     */
    private static function registerModule(string $path, string $name, string $type, string $namespace): void
    {
        // Filter by composer "type": only accept semitexa-module
        $composerType = self::readComposerType($path);
        if (!in_array($composerType, ['semitexa-module', 'semitexa-theme'], true)) {
            // Skip non-semitexa modules/packages
            return;
        }

        $meta = self::readComposerMeta($path);

        // Default template path inside module
        $defaultTemplatePath = $path . '/Application/View/templates';

        // Aliases
        $aliases = [];
        $aliases[] = $name;
        $friendly = $name;
        foreach (["semitexa-module-", "module-"] as $prefix) {
            if (str_starts_with($friendly, $prefix)) {
                $friendly = substr($friendly, strlen($prefix));
            }
        }
        if ($friendly !== $name) {
            $aliases[] = $friendly;
        }
        if (!empty($meta['template_alias'])) {
            $aliases[] = (string)$meta['template_alias'];
        }
        $aliases = array_values(array_unique($aliases));

        // Template paths
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

        // Check if module with same path already registered (avoid duplicates from symlinks)
        $realPath = realpath($path) ?: $path;
        foreach (self::$modules as $existing) {
            $existingRealPath = realpath($existing['path']) ?: $existing['path'];
            if ($existingRealPath === $realPath) {
                // Module already registered, skip
                return;
            }
        }
        
        self::$modules[] = [
            'path' => $realPath,
            'name' => $name,
            'type' => $type,
            'namespace' => $namespace,
            'composerType' => $composerType,
            'aliases' => $aliases,
            'templatePaths' => $templatePaths,
            'extends' => $meta['extends'] ?? null,
            'controllers' => self::findControllers($path, $namespace),
            'routes' => self::findRoutes($path, $namespace),
            'autoloadPsr4' => self::resolveAutoloadPsr4($path, $meta['autoload_psr4'] ?? []),
            'config' => self::findModuleConfig($path, $namespace)
        ];
        
    }

    private static function readComposerType(string $modulePath): ?string
    {
        $composerJson = $modulePath . '/composer.json';
        if (!is_file($composerJson)) {
            return null;
        }
        try {
            $json = json_decode((string)file_get_contents($composerJson), true, 512, JSON_THROW_ON_ERROR);
            return $json['type'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function readComposerMeta(string $modulePath): array
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
                $meta['template_paths'] = $extra['template_paths'];
            }
            if (isset($extra['extends']) && is_string($extra['extends']) && $extra['extends'] !== '') {
                $meta['extends'] = $extra['extends'];
            }
            if (!empty($json['autoload']['psr-4']) && is_array($json['autoload']['psr-4'])) {
                $meta['autoload_psr4'] = $json['autoload']['psr-4'];
            }
        } catch (\Throwable $e) {
            // ignore invalid json
        }
        return $meta;
    }

    private static function resolveAutoloadPsr4(string $modulePath, array $psr4): array
    {
        $resolved = [];
        foreach ($psr4 as $prefix => $paths) {
            if (!is_string($prefix) || $prefix === '') {
                continue;
            }
            $normalizedPrefix = rtrim($prefix, '\\') . '\\';
            $paths = is_array($paths) ? $paths : [$paths];
            foreach ($paths as $rel) {
                if (!is_string($rel) || $rel === '') {
                    continue;
                }
                $full = rtrim($modulePath, '/') . '/' . ltrim($rel, '/');
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
     * Find controllers in module
     */
    private static function findControllers(string $path, string $namespace): array
    {
        $controllers = [];
        $files = glob($path . '/*Controller.php');
        
        foreach ($files as $file) {
            $className = basename($file, '.php');
            $controllers[] = $namespace . '\\' . $className;
        }
        
        return $controllers;
    }
    
    /**
     * Find routes in module
     */
    private static function findRoutes(string $path, string $namespace): array
    {
        // This will be implemented when we integrate with AttributeDiscovery
        return [];
    }

    private static function findModuleConfig(string $path, string $namespace): array
    {
        return ['active' => true, 'role' => 'observer'];
    }

    private static function discoverPackageModules(string $projectRoot): array
    {
        return self::discoverModulesInRoot($projectRoot . '/packages');
    }

    private static function discoverModulesInRoot(string $root): array
    {
        $modules = [];
        if (!is_dir($root)) {
            return $modules;
        }

        $vendorDirs = glob(rtrim($root, '/') . '/*', GLOB_ONLYDIR);
        foreach ($vendorDirs as $vendorDir) {
            $packageDirs = glob($vendorDir . '/*', GLOB_ONLYDIR);
            foreach ($packageDirs as $dir) {
                $packageName = basename($dir);
                $namespace = self::inferNamespaceFromComposer($dir)
                    ?? self::buildNamespaceFromVendor(basename($vendorDir), $packageName);

                $modules[] = [
                    'path' => $dir,
                    'name' => $packageName,
                    'namespace' => $namespace
                ];
            }
        }

        return $modules;
    }

    private static function inferNamespaceFromComposer(string $modulePath): ?string
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
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    private static function buildNamespaceFromVendor(string $vendor, string $package): string
    {
        return Str::toStudly($vendor) . '\\' . Str::toStudly($package);
    }

    
}
