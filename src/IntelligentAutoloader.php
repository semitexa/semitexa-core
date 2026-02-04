<?php

declare(strict_types=1);

namespace Semitexa\Core;

/**
 * Intelligent Autoloader that automatically discovers classes
 * 
 * This autoloader scans the entire project and automatically maps
 * namespaces to directories without any manual configuration.
 */
class IntelligentAutoloader
{
    private static array $classMap = [];
    private static bool $initialized = false;
    
    private static function allowNamespacePrefix(string $prefix): void
    {
        if ($prefix === '') {
            return;
        }
        $normalized = rtrim($prefix, '\\') . '\\';
        self::$allowedNamespacePrefixes[$normalized] = true;
    }
    
    private static function isNamespaceAllowed(string $namespace): bool
    {
        $normalized = rtrim($namespace, '\\') . '\\';
        foreach (array_keys(self::$allowedNamespacePrefixes) as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }
        return false;
    }
    private static array $allowedNamespacePrefixes = [
        'Semitexa\\' => true,
        'Semitexa\\Modules\\' => true,
    ];
    
    /**
     * Initialize the intelligent autoloader
     */
    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }
        
        
        $startTime = microtime(true);
        self::buildClassMap();
        $endTime = microtime(true);
        
        
        // Register autoloader only if not already registered
        if (!in_array([self::class, 'autoload'], spl_autoload_functions())) {
            spl_autoload_register([self::class, 'autoload']);
        }
        
        self::$initialized = true;
    }
    
    /**
     * Build complete class map by scanning all PHP files
     */
    private static function buildClassMap(): void
    {
        $projectRoot = self::getProjectRoot();
        
        // Scan all relevant directories
        $directories = [
            $projectRoot . '/src',
            $projectRoot . '/vendor/semitexa'
        ];
        
        // Also scan packages directory for local development
        if (is_dir($projectRoot . '/packages')) {
            $directories[] = $projectRoot . '/packages';
        }
        
        // Always scan src/modules for local custom modules
        if (is_dir($projectRoot . '/src/modules')) {
            $directories[] = $projectRoot . '/src/modules';
        }
        
        // Include module-specific PSR-4 paths (may use non-Semitexa namespaces)
        $moduleMappings = self::getModuleAutoloadMappings();
        foreach ($moduleMappings as $prefix => $paths) {
            self::allowNamespacePrefix($prefix);
            foreach ($paths as $path) {
                $directories[] = $path;
            }
        }
        
        $directories = array_values(array_unique($directories));
        
        // Add Semitexa\Core classes manually
        self::addSemitexaCoreClasses();
        
        foreach ($directories as $directory) {
            if (is_dir($directory)) {
                self::scanDirectory($directory);
            }
        }
    }
    
    /**
     * Scan directory recursively for PHP files
     */
    private static function scanDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                self::analyzeFile($file->getPathname());
            }
        }
    }
    
    /**
     * Analyze PHP file and extract class information
     */
    private static function analyzeFile(string $filePath): void
    {
        try {
            $content = file_get_contents($filePath);
            
            // Extract namespace
            if (!preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatches)) {
                return; // No namespace found
            }
            
            $namespace = $namespaceMatches[1];
            if (!self::isNamespaceAllowed($namespace)) {
                return;
            }
            
            // Extract class/trait/interface name
            if (!preg_match('/\b(class|interface|trait)\s+(\w+)/', $content, $classMatches)) {
                return; // No declaration found
            }
            
            $className = $classMatches[2];
            $fullClassName = $namespace . '\\' . $className;
            
            // Store in class map
            self::$classMap[$fullClassName] = $filePath;
            
        } catch (\Throwable $e) {
            // Skip files that can't be analyzed
        }
    }
    
    /**
     * Autoload function
     */
    public static function autoload(string $className): void
    {
        if (isset(self::$classMap[$className])) {
            require_once self::$classMap[$className];
        }
    }
    
    /**
     * Get all discovered classes
     */
    public static function getClassMap(): array
    {
        return self::$classMap;
    }
    
    /**
     * Get classes by namespace prefix
     */
    public static function getClassesByNamespace(string $namespacePrefix): array
    {
        return array_filter(
            self::$classMap,
            fn($className) => str_starts_with($className, $namespacePrefix),
            ARRAY_FILTER_USE_KEY
        );
    }
    
    /**
     * Get all namespaces
     */
    public static function getNamespaces(): array
    {
        $namespaces = [];
        
        foreach (array_keys(self::$classMap) as $className) {
            $namespace = substr($className, 0, strrpos($className, '\\'));
            $namespaces[$namespace] = true;
        }
        
        return array_keys($namespaces);
    }
    
    /**
     * Find classes with specific attributes
     */
    public static function findClassesWithAttribute(string $attributeClass): array
    {
        // Ensure autoloader is initialized
        if (!self::$initialized) {
            self::initialize();
        }
        
        $classes = [];
        $classMapSize = count(self::$classMap);
        
        // First, check classes in classMap
        foreach (self::$classMap as $className => $filePath) {
            // Skip Composer plugin classes (only loaded by Composer, not by CLI)
            if (str_starts_with($className, 'Semitexa\\Core\\Composer\\')) {
                continue;
            }

            // Load class if not already loaded
            if (!class_exists($className) && !interface_exists($className) && !trait_exists($className)) {
                if (file_exists($filePath)) {
                    try {
                        require_once $filePath;
                    } catch (\Throwable $e) {
                        // Skip classes that can't be loaded (e.g., wrapper classes with circular dependencies)
                        if (str_contains($e->getMessage(), 'not found') && str_contains($e->getMessage(), 'Semitexa\\Modules\\')) {
                            continue;
                        }
                        // For other errors, continue to reflection check (might be recoverable)
                    }
                }
            }
            
            if (class_exists($className) || interface_exists($className) || trait_exists($className)) {
                try {
                    $reflection = new \ReflectionClass($className);
                    
                    if ($reflection->getAttributes($attributeClass)) {
                        $classes[] = $className;
                    }
                } catch (\Throwable $e) {
                    // Skip classes that can't be reflected
                }
            }
        }
        
        // Also check already loaded classes that might not be in classMap yet
        // This is useful for classes loaded via Composer autoloader
        $declaredClasses = get_declared_classes();
        foreach ($declaredClasses as $className) {
            // Only check Semitexa classes
            if (!str_starts_with($className, 'Semitexa\\')) {
                continue;
            }
            
            // Skip if already found
            if (in_array($className, $classes)) {
                continue;
            }
            
            // Skip if not in allowed namespace
            if (!self::isNamespaceAllowed($className)) {
                continue;
            }
            
            try {
                $reflection = new \ReflectionClass($className);
                
                if ($reflection->getAttributes($attributeClass)) {
                    $classes[] = $className;
                }
            } catch (\Throwable $e) {
                // Skip classes that can't be reflected
            }
        }
        
        return array_unique($classes);
    }
    
    /**
     * Get project root directory
     */
    private static function getProjectRoot(): string
    {
        $currentDir = __DIR__;
        
        // Go up from vendor/semitexa/core/src/ to project root
        return dirname($currentDir, 4);
    }
    
    /**
     * Generate PSR-4 mappings automatically
     */
    public static function generatePsr4Mappings(): array
    {
        $mappings = [];
        $namespaces = self::getNamespaces();
        
        foreach ($namespaces as $namespace) {
            // Find the best directory for this namespace
            $directory = self::findBestDirectory($namespace);
            
            if ($directory) {
                $mappings[$namespace . '\\'] = $directory;
            }
        }
        
        return $mappings;
    }
    
    /**
     * Find the best directory for a namespace
     */
    private static function findBestDirectory(string $namespace): ?string
    {
        $classes = self::getClassesByNamespace($namespace);
        
        if (empty($classes)) {
            return null;
        }
        
        // Get the first class file to determine directory
        $firstClass = array_values($classes)[0];
        $filePath = $firstClass;
        
        // Extract directory from file path
        $projectRoot = self::getProjectRoot();
        $relativePath = str_replace($projectRoot . '/', '', dirname($filePath));
        
        // Special handling for Semitexa\Core namespace
        if ($namespace === 'Semitexa\\Core') {
            return 'vendor/semitexa/core/src/';
        }
        
        return $relativePath . '/';
    }
    
    /**
     * Add Semitexa\Core classes manually
     */
    private static function addSemitexaCoreClasses(): void
    {
        $coreClasses = [
            'Semitexa\\Core\\Application' => 'vendor/semitexa/core/src/Application.php',
            'Semitexa\\Core\\Environment' => 'vendor/semitexa/core/src/Environment.php',
            'Semitexa\\Core\\ErrorHandler' => 'vendor/semitexa/core/src/ErrorHandler.php',
            'Semitexa\\Core\\Request' => 'vendor/semitexa/core/src/Request.php',
            'Semitexa\\Core\\RequestFactory' => 'vendor/semitexa/core/src/RequestFactory.php',
            'Semitexa\\Core\\Response' => 'vendor/semitexa/core/src/Response.php',
            'Semitexa\\Core\\ModuleRegistry' => 'vendor/semitexa/core/src/ModuleRegistry.php',
            'Semitexa\\Core\\AutoloaderGenerator' => 'vendor/semitexa/core/src/AutoloaderGenerator.php',
            'Semitexa\\Core\\IntelligentAutoloader' => 'vendor/semitexa/core/src/IntelligentAutoloader.php',
            'Semitexa\\Core\\Discovery\\AttributeDiscovery' => 'vendor/semitexa/core/src/Discovery/AttributeDiscovery.php'
        ];
        
        $projectRoot = self::getProjectRoot();
        
        foreach ($coreClasses as $className => $relativePath) {
            $fullPath = $projectRoot . '/' . $relativePath;
            if (file_exists($fullPath)) {
                self::$classMap[$className] = $fullPath;
            }
        }
    }

    private static function getModuleAutoloadMappings(): array
    {
        if (!class_exists(ModuleRegistry::class)) {
            return [];
        }
        ModuleRegistry::initialize();
        return ModuleRegistry::getModuleAutoloadMappings();
    }
}
