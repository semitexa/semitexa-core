<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

/**
 * Creates DTO instances with dynamically composed traits from AsPayloadPart/AsResourcePart.
 *
 * When traits are specified, a wrapper class is generated via eval() that extends the base
 * and uses all applicable traits. The wrapper is created once per Swoole worker and cached.
 */
final class PayloadFactory
{
    /** @var array<string, class-string> */
    private static array $classCache = [];

    /**
     * @param list<string> $traits
     */
    public static function createInstance(string $baseClass, array $traits): object
    {
        $traits = array_values(array_unique(array_map(
            static fn(string $t): string => ltrim($t, '\\'),
            $traits
        )));
        sort($traits);

        if (empty($traits)) {
            return new $baseClass();
        }

        $cacheKey = $baseClass . "\0" . implode("\0", $traits);
        if (!isset(self::$classCache[$cacheKey])) {
            self::$classCache[$cacheKey] = self::buildWrapperClass($cacheKey, $baseClass, $traits);
        }

        $wrapperClass = self::$classCache[$cacheKey];
        return new $wrapperClass();
    }

    /**
     * @param list<string> $traits
     * @return class-string
     */
    private static function buildWrapperClass(string $signature, string $baseClass, array $traits): string
    {
        // Security: validate all class/trait names against strict FQCN pattern
        // to prevent code injection via eval() (VULN-001)
        if (!preg_match('/^[A-Za-z0-9_\\\\]+$/', $baseClass)) {
            throw new \InvalidArgumentException(
                'Invalid base class name for PayloadFactory. Only alphanumeric, underscore, and backslash allowed.'
            );
        }

        foreach ($traits as $trait) {
            if (!preg_match('/^[A-Za-z0-9_\\\\]+$/', $trait)) {
                throw new \InvalidArgumentException(
                    "Invalid trait name '{$trait}' for PayloadFactory. Only alphanumeric, underscore, and backslash allowed."
                );
            }
        }

        $hash = substr(hash('sha256', $signature), 0, 16);
        $className = 'PayloadWrapper_' . $hash;
        $fqn = 'Semitexa\\Runtime\\' . $className;

        if (!class_exists($fqn, false)) {
            $traitUses = implode("\n    ", array_map(
                fn(string $t) => 'use \\' . $t . ';',
                $traits
            ));
            eval("namespace Semitexa\\Runtime; class {$className} extends \\" . ltrim($baseClass, '\\') . " { {$traitUses} }");
        }

        /** @var class-string $fqn */
        return $fqn;
    }
}
