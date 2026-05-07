<?php

declare(strict_types=1);

namespace Semitexa\Core\Validation\Trait;

use InvalidArgumentException;

/**
 * Accumulator-style numeric validators.
 *
 * Conventions:
 *
 *  - `null` values are silently accepted so callers can chain
 *    {@see PresenceValidationTrait::validateOptional()}.
 *  - PHP-type assertion belongs to {@see TypeValidationTrait}; these
 *    methods accept `int|float|null` directly via parameter types and
 *    rely on the framework's hydrator to have produced the right type.
 *  - Divisor / factor of `0` is a developer error and throws
 *    {@see InvalidArgumentException}.
 *  - Float divisibility uses a tolerance comparison rather than the `%`
 *    operator, since `%` truncates floats. The tolerance is
 *    {@see FLOAT_DIVISIBILITY_EPSILON}; adjust at the call site by
 *    rounding inputs first if a different precision is required.
 */
trait NumericValidationTrait
{
    private const float FLOAT_DIVISIBILITY_EPSILON = 1e-9;

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validatePositive(array &$errors, string $field, int|float|null $value): void
    {
        if ($value === null) {
            return;
        }
        if ($value <= 0) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be greater than 0.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validatePositiveOrZero(array &$errors, string $field, int|float|null $value): void
    {
        if ($value === null) {
            return;
        }
        if ($value < 0) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be greater than or equal to 0.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateNegative(array &$errors, string $field, int|float|null $value): void
    {
        if ($value === null) {
            return;
        }
        if ($value >= 0) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be less than 0.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateNegativeOrZero(array &$errors, string $field, int|float|null $value): void
    {
        if ($value === null) {
            return;
        }
        if ($value > 0) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be less than or equal to 0.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateGreaterThan(
        array &$errors,
        string $field,
        int|float|null $value,
        int|float $threshold,
    ): void {
        if ($value === null) {
            return;
        }
        if ($value <= $threshold) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = "This value should be greater than {$threshold}.";
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateGreaterThanOrEqual(
        array &$errors,
        string $field,
        int|float|null $value,
        int|float $threshold,
    ): void {
        if ($value === null) {
            return;
        }
        if ($value < $threshold) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = "This value should be greater than or equal to {$threshold}.";
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateLessThan(
        array &$errors,
        string $field,
        int|float|null $value,
        int|float $threshold,
    ): void {
        if ($value === null) {
            return;
        }
        if ($value >= $threshold) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = "This value should be less than {$threshold}.";
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateLessThanOrEqual(
        array &$errors,
        string $field,
        int|float|null $value,
        int|float $threshold,
    ): void {
        if ($value === null) {
            return;
        }
        if ($value > $threshold) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = "This value should be less than or equal to {$threshold}.";
        }
    }

    /**
     * Either bound can be `null` to leave that side open.
     *
     * @param array<string, list<string>> $errors
     */
    protected function validateRange(
        array &$errors,
        string $field,
        int|float|null $value,
        int|float|null $min = null,
        int|float|null $max = null,
    ): void {
        if ($value === null) {
            return;
        }
        if ($min !== null && $value < $min) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = "This value should be greater than or equal to {$min}.";
        }
        if ($max !== null && $value > $max) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = "This value should be less than or equal to {$max}.";
        }
    }

    /**
     * @param array<string, list<string>> $errors
     * @throws InvalidArgumentException when `$divisor` is 0
     */
    protected function validateDivisibleBy(
        array &$errors,
        string $field,
        int|float|null $value,
        int|float $divisor,
    ): void {
        if ($value === null) {
            return;
        }
        if ($divisor === 0 || $divisor === 0.0) {
            throw new InvalidArgumentException('Divisor must be non-zero.');
        }

        if (! self::isMultiple($value, $divisor)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = "This value should be divisible by {$divisor}.";
        }
    }

    /**
     * @param array<string, list<string>> $errors
     * @throws InvalidArgumentException when `$factor` is 0
     */
    protected function validateMultipleOf(
        array &$errors,
        string $field,
        int|float|null $value,
        int|float $factor,
    ): void {
        if ($value === null) {
            return;
        }
        if ($factor === 0 || $factor === 0.0) {
            throw new InvalidArgumentException('Factor must be non-zero.');
        }

        if (! self::isMultiple($value, $factor)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = "This value should be a multiple of {$factor}.";
        }
    }

    private static function isMultiple(int|float $value, int|float $divisor): bool
    {
        if (is_int($value) && is_int($divisor)) {
            return ($value % $divisor) === 0;
        }

        $ratio = $value / $divisor;
        return abs($ratio - round($ratio)) <= self::FLOAT_DIVISIBILITY_EPSILON;
    }
}
