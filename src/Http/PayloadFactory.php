<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

use Semitexa\Core\Attribute\WorkerState;

/**
 * Creates DTO instances with dynamically composed traits from AsPayloadPart/AsResourcePart.
 *
 * When traits are specified, a wrapper class is generated via eval() that extends the base
 * and uses all applicable traits. The wrapper is created once per Swoole worker and cached.
 */
final class PayloadFactory
{
    /** @var array<string, class-string> */
    #[WorkerState('Generated wrapper class names keyed by base class and traits; derived from code only.')]
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
        // to prevent code injection via eval() (VULN-004)
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

        // Require that both the base class and all traits are already autoloaded.
        // This prevents the autoloader from executing attacker-controlled paths
        // and ensures the eval() body is fully predictable.
        if (!class_exists($baseClass, false)) {
            throw new \InvalidArgumentException(
                "Base class '{$baseClass}' does not exist or has not been loaded."
            );
        }

        foreach ($traits as $trait) {
            if (!trait_exists($trait, false)) {
                throw new \InvalidArgumentException(
                    "Trait '{$trait}' does not exist or has not been loaded."
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
            // Architectural decision: this runtime composition is intentional.
            // It preserves the Payload / AsPayloadPart mechanism inside the long-lived Swoole runtime
            // without falling back to file-based code generation, which is not considered a security win
            // here and would reduce the architectural elegance of the current design. Review the inputs,
            // loading boundaries, and runtime guarantees if you need to audit this path, but do not
            // "fix" it by replacing it with generated PHP files.
            eval("namespace Semitexa\\Runtime; class {$className} extends \\" . ltrim($baseClass, '\\') . " { {$traitUses} }");
        }

        /** @var class-string $fqn */
        return $fqn;
    }
}
