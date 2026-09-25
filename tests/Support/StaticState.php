<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Support;

/**
 * Snapshot and restore of a class's static properties, for tests that must
 * mutate process-global state (a registry, a cached root, a call log).
 *
 * A test that resets such state and does not put it back passes alone and
 * fails in company: whatever the NEXT test relied on is gone. Taking the
 * snapshot before the first mutation and restoring it in tearDown() leaves
 * the process as the test found it — including state it did not itself set.
 */
final class StaticState
{
    /**
     * @param class-string $class
     * @param list<string>|null $properties null = every static property the class declares
     * @return array{class: class-string, values: array<string, mixed>}
     */
    public static function snapshot(string $class, ?array $properties = null): array
    {
        $reflection = new \ReflectionClass($class);
        $values = [];
        foreach ($reflection->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
            if ($property->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            if ($properties !== null && !in_array($property->getName(), $properties, true)) {
                continue;
            }
            $values[$property->getName()] = $property->getValue();
        }

        return ['class' => $class, 'values' => $values];
    }

    /** @param array{class: class-string, values: array<string, mixed>} $snapshot */
    public static function restore(array $snapshot): void
    {
        $reflection = new \ReflectionClass($snapshot['class']);
        foreach ($snapshot['values'] as $name => $value) {
            $reflection->getProperty($name)->setValue(null, $value);
        }
    }
}
