<?php

declare(strict_types=1);

namespace Semitexa\Core\Validation\Trait;

use BackedEnum;
use Countable;
use InvalidArgumentException;
use UnitEnum;

/**
 * Accumulator-style choice / count / enum validators.
 *
 * Conventions:
 *
 *  - `null` values are silently accepted so callers can chain
 *    {@see PresenceValidationTrait::validateOptional()}.
 *  - An empty `$choices` / `$disallowed` list, a negative count bound, or
 *    a non-enum class is a developer error and throws
 *    {@see InvalidArgumentException}.
 *  - {@see validateUnique()} supports scalar items (int, float, string,
 *    bool) plus null. Object / resource / nested-array items would force
 *    a serialization choice; this trait treats them as a developer error
 *    rather than ship a weak fake.
 *  - {@see validateEnumChoice()} and
 *    {@see validateBackedEnumChoice()} duplicate the semantics of
 *    {@see TypeValidationTrait::validateEnumCase()} /
 *    {@see TypeValidationTrait::validateBackedEnumValue()} so a payload
 *    using only `ChoiceValidationTrait` does not need to mix in
 *    `TypeValidationTrait` for the enum sugar.
 */
trait ChoiceValidationTrait
{
    /**
     * @param array<string, list<string>> $errors
     * @param array<int, mixed>           $choices
     * @throws InvalidArgumentException   when `$choices` is empty
     */
    protected function validateChoice(
        array &$errors,
        string $field,
        mixed $value,
        array $choices,
        bool $strict = true,
    ): void {
        if ($choices === []) {
            throw new InvalidArgumentException('Choice list must not be empty.');
        }
        if ($value === null) {
            return;
        }
        if (! in_array($value, $choices, $strict)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value is not one of the allowed choices.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     * @param array<int, mixed>           $disallowed
     * @throws InvalidArgumentException   when `$disallowed` is empty
     */
    protected function validateNotIn(
        array &$errors,
        string $field,
        mixed $value,
        array $disallowed,
        bool $strict = true,
    ): void {
        if ($disallowed === []) {
            throw new InvalidArgumentException('Disallowed list must not be empty.');
        }
        if ($value === null) {
            return;
        }
        if (in_array($value, $disallowed, $strict)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value is not allowed.';
        }
    }

    /**
     * @param array<string, list<string>>     $errors
     * @param Countable|array<mixed,mixed>|null $value
     * @throws InvalidArgumentException       when `$min` or `$max` is negative
     */
    protected function validateCount(
        array &$errors,
        string $field,
        Countable|array|null $value,
        ?int $min = null,
        ?int $max = null,
    ): void {
        if ($value === null) {
            return;
        }
        $this->guardNonNegative($min, '$min');
        $this->guardNonNegative($max, '$max');

        $count = count($value);
        if ($min !== null && $count < $min) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = "This collection should contain at least {$min} item(s).";
        }
        if ($max !== null && $count > $max) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = "This collection should contain at most {$max} item(s).";
        }
    }

    /**
     * @param array<string, list<string>>     $errors
     * @param Countable|array<mixed,mixed>|null $value
     */
    protected function validateMinCount(array &$errors, string $field, Countable|array|null $value, int $min): void
    {
        $this->validateCount($errors, $field, $value, $min, null);
    }

    /**
     * @param array<string, list<string>>     $errors
     * @param Countable|array<mixed,mixed>|null $value
     */
    protected function validateMaxCount(array &$errors, string $field, Countable|array|null $value, int $max): void
    {
        $this->validateCount($errors, $field, $value, null, $max);
    }

    /**
     * @param array<string, list<string>>     $errors
     * @param Countable|array<mixed,mixed>|null $value
     */
    protected function validateExactCount(
        array &$errors,
        string $field,
        Countable|array|null $value,
        int $count,
    ): void {
        if ($value === null) {
            return;
        }
        $this->guardNonNegative($count, '$count');
        if (count($value) !== $count) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = "This collection should contain exactly {$count} item(s).";
        }
    }

    /**
     * @param array<string, list<string>>      $errors
     * @param array<int|string, mixed>|null    $value
     * @throws InvalidArgumentException        when items are not scalar/null
     */
    protected function validateUnique(
        array &$errors,
        string $field,
        ?array $value,
        bool $strict = true,
    ): void {
        if ($value === null) {
            return;
        }
        foreach ($value as $item) {
            if ($item !== null && ! is_scalar($item)) {
                throw new InvalidArgumentException(
                    'validateUnique() supports only null and scalar values; received ' . get_debug_type($item) . '.',
                );
            }
        }

        $seen = [];
        foreach ($value as $item) {
            foreach ($seen as $prior) {
                $duplicate = $strict ? $item === $prior : $item == $prior;
                if ($duplicate) {
                    $errors[$field] = $errors[$field] ?? [];
                    $errors[$field][] = 'This collection should contain only unique values.';
                    return;
                }
            }
            $seen[] = $item;
        }
    }

    /**
     * @param array<string, list<string>> $errors
     * @param class-string<UnitEnum>      $enumClass
     * @throws InvalidArgumentException   when `$enumClass` is not an enum
     */
    protected function validateEnumChoice(
        array &$errors,
        string $field,
        mixed $value,
        string $enumClass,
    ): void {
        if (! enum_exists($enumClass)) {
            throw new InvalidArgumentException("Class {$enumClass} is not an enum.");
        }
        if ($value === null) {
            return;
        }
        if (! ($value instanceof $enumClass)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a valid enum case.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     * @param class-string<BackedEnum>    $enumClass
     * @throws InvalidArgumentException   when `$enumClass` is not a backed enum
     */
    protected function validateBackedEnumChoice(
        array &$errors,
        string $field,
        mixed $value,
        string $enumClass,
    ): void {
        if (! enum_exists($enumClass) || ! is_subclass_of($enumClass, BackedEnum::class)) {
            throw new InvalidArgumentException("Class {$enumClass} is not a backed enum.");
        }
        if ($value === null) {
            return;
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

    private function guardNonNegative(?int $bound, string $name): void
    {
        if ($bound !== null && $bound < 0) {
            throw new InvalidArgumentException("{$name} must be non-negative.");
        }
    }
}
