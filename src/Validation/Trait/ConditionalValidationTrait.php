<?php

declare(strict_types=1);

namespace Semitexa\Core\Validation\Trait;

/**
 * Accumulator-style conditional validators.
 *
 * "Blank" matches the convention used by
 * {@see PresenceValidationTrait::validateNotBlank()}: a value is blank
 * when it is `null`, the empty string, a whitespace-only string, or the
 * empty array. Other scalar values (including `0`, `false`, `'0'`) are
 * **not** blank.
 *
 * Callback shape (the canonical accumulator validator):
 *
 *     callable(array &$errors, string $field, mixed $value): void
 *
 * Conventions:
 *
 *  - The trait never reflects into the surrounding payload: the caller
 *    resolves the boolean condition or the comparison values and passes
 *    them in.
 *  - {@see validateRequiredIf()} / {@see validateProhibitedIf()} care
 *    about `null` and blank semantics by design.
 *  - {@see validateSometimes()} short-circuits on blank values, mirroring
 *    Laravel's "sometimes" rule.
 */
trait ConditionalValidationTrait
{
    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateRequiredIf(
        array &$errors,
        string $field,
        mixed $value,
        bool $condition,
    ): void {
        if ($condition && $this->isBlankForCondition($value)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value is required.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateProhibitedIf(
        array &$errors,
        string $field,
        mixed $value,
        bool $condition,
    ): void {
        if ($condition && ! $this->isBlankForCondition($value)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value is not allowed in this context.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     * @param array<int, mixed>           $otherValues
     */
    protected function validateRequiredWith(
        array &$errors,
        string $field,
        mixed $value,
        array $otherValues,
    ): void {
        $anyOtherPresent = false;
        foreach ($otherValues as $other) {
            if (! $this->isBlankForCondition($other)) {
                $anyOtherPresent = true;
                break;
            }
        }
        if ($anyOtherPresent && $this->isBlankForCondition($value)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value is required.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     * @param array<int, mixed>           $otherValues
     */
    protected function validateRequiredWithout(
        array &$errors,
        string $field,
        mixed $value,
        array $otherValues,
    ): void {
        $anyOtherBlank = false;
        foreach ($otherValues as $other) {
            if ($this->isBlankForCondition($other)) {
                $anyOtherBlank = true;
                break;
            }
        }
        if ($anyOtherBlank && $this->isBlankForCondition($value)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value is required.';
        }
    }

    /**
     * @param array<string, list<string>>                                  $errors
     * @param callable(array<string, list<string>>&, string, mixed): void  $validator
     */
    protected function validateIf(
        array &$errors,
        string $field,
        mixed $value,
        bool $condition,
        callable $validator,
    ): void {
        if ($condition) {
            $validator($errors, $field, $value);
        }
    }

    /**
     * @param array<string, list<string>>                                  $errors
     * @param callable(array<string, list<string>>&, string, mixed): void  $validator
     */
    protected function validateSometimes(
        array &$errors,
        string $field,
        mixed $value,
        callable $validator,
    ): void {
        if ($this->isBlankForCondition($value)) {
            return;
        }
        $validator($errors, $field, $value);
    }

    private function isBlankForCondition(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value) && trim($value) === '') {
            return true;
        }
        if (is_array($value) && $value === []) {
            return true;
        }
        return false;
    }
}
