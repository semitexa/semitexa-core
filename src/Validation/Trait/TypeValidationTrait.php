<?php

declare(strict_types=1);

namespace Semitexa\Core\Validation\Trait;

use BackedEnum;
use InvalidArgumentException;
use UnitEnum;

/**
 * Accumulator-style type validators.
 *
 * Strict type checks: numeric strings are not integers, integers are not
 * floats, booleans are not strings. The framework's payload hydrator is
 * responsible for any coercion that must happen before validation; once
 * a value reaches `validate()`, its PHP type is the type to assert against.
 *
 * Developer-error paths — for example asking
 * {@see validateBackedEnumValue()} to validate against a class that is not
 * a backed enum — throw {@see InvalidArgumentException}. End-user input
 * never causes those throws.
 */
trait TypeValidationTrait
{
    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateString(array &$errors, string $field, mixed $value): void
    {
        if (! is_string($value)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a string.';
        }
    }

    /**
     * Accepts only `int`. Numeric strings are not integers.
     *
     * @param array<string, list<string>> $errors
     */
    protected function validateInteger(array &$errors, string $field, mixed $value): void
    {
        if (! is_int($value)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be an integer.';
        }
    }

    /**
     * Accepts only `float`. Integers are not accepted; use
     * {@see validateNumber()} when both are valid.
     *
     * @param array<string, list<string>> $errors
     */
    protected function validateFloat(array &$errors, string $field, mixed $value): void
    {
        if (! is_float($value)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a float.';
        }
    }

    /**
     * Accepts `int` or `float`. Numeric strings are not numbers.
     *
     * @param array<string, list<string>> $errors
     */
    protected function validateNumber(array &$errors, string $field, mixed $value): void
    {
        if (! is_int($value) && ! is_float($value)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a number.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateBoolean(array &$errors, string $field, mixed $value): void
    {
        if (! is_bool($value)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a boolean.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateArray(array &$errors, string $field, mixed $value): void
    {
        if (! is_array($value)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be an array.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateObject(array &$errors, string $field, mixed $value): void
    {
        if (! is_object($value)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be an object.';
        }
    }

    /**
     * Accepts any value for which `is_iterable()` is true (PHP arrays plus
     * any object implementing {@see \Traversable}).
     *
     * @param array<string, list<string>> $errors
     */
    protected function validateIterable(array &$errors, string $field, mixed $value): void
    {
        if (! is_iterable($value)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be iterable.';
        }
    }

    /**
     * Accepts a value only when it is an instance of `$enumClass`
     * (covers both pure and backed enums).
     *
     * @param array<string, list<string>>      $errors
     * @param class-string<UnitEnum>           $enumClass
     * @throws InvalidArgumentException        when `$enumClass` is not an enum class
     */
    protected function validateEnumCase(
        array &$errors,
        string $field,
        mixed $value,
        string $enumClass,
    ): void {
        if (! enum_exists($enumClass)) {
            throw new InvalidArgumentException(
                "Class {$enumClass} is not an enum.",
            );
        }

        if (! ($value instanceof $enumClass)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a valid enum case.';
        }
    }

    /**
     * Accepts a value only when `$enumClass::tryFrom($value)` resolves to a
     * case. The class must be a backed enum; passing a pure enum is a
     * developer error and throws {@see InvalidArgumentException}.
     *
     * @param array<string, list<string>>     $errors
     * @param class-string<BackedEnum>        $enumClass
     * @throws InvalidArgumentException       when `$enumClass` is not a backed enum
     */
    protected function validateBackedEnumValue(
        array &$errors,
        string $field,
        mixed $value,
        string $enumClass,
    ): void {
        if (! enum_exists($enumClass) || ! is_subclass_of($enumClass, BackedEnum::class)) {
            throw new InvalidArgumentException(
                "Class {$enumClass} is not a backed enum.",
            );
        }

        if (! is_int($value) && ! is_string($value)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a valid enum value.';
            return;
        }

        if ($enumClass::tryFrom($value) === null) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a valid enum value.';
        }
    }
}
