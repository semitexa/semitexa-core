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

        $composerClassMap = require ProjectRoot::get() . '/vendor/composer/autoload_classmap.php';

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

                if ($reflection->getAttributes($attributeClass)) {
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
