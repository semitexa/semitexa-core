<?php

declare(strict_types=1);

namespace Semitexa\Core\Validation\Trait;

/**
 * Accumulator-style presence validators.
 *
 * All methods follow the framework's accumulator convention: append a
 * human-readable message to `array<string, list<string>> $errors` keyed by
 * `$field` when the value violates the rule, and do nothing otherwise.
 *
 * Values that "look absent" are treated as follows:
 *
 *  - `null`            → considered missing.
 *  - `''`              → considered missing for {@see validateNotBlank()}.
 *  - whitespace string → considered missing for {@see validateNotBlank()}.
 *  - `[]`              → considered missing for {@see validateNotBlank()}.
 *
 * The setter-time {@see NotBlankValidationTrait} stays the canonical
 * choice for setters that throw immediately on a blank value. This trait
 * complements it for payloads that aggregate errors inside a
 * `ValidatablePayloadInterface::validate()` hook.
 */
trait PresenceValidationTrait
{
    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateRequired(array &$errors, string $field, mixed $value): void
    {
        if ($value === null) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value is required.';
        }
    }

    /**
     * Returns false when the value should be skipped (null), true otherwise.
     * Use as a guard inside `validate()` to short-circuit dependent checks:
     *
     *     if (! $this->validateOptional($errors, 'website', $this->website)) {
     *         return; // nothing further to validate for this field
     *     }
     *     $this->validateUrl($errors, 'website', $this->website);
     *
     * The `&$errors` parameter is accepted for signature symmetry with the
     * other accumulator validators; this method never appends.
     *
     * @param array<string, list<string>> $errors
     */
    protected function validateOptional(array &$errors, string $field, mixed $value): bool
    {
        unset($errors, $field);

        return $value !== null;
    }

    /**
     * Rejects null, '', whitespace-only strings, and empty arrays.
     * Non-string/non-array scalars (int, float, bool) are accepted —
     * "blank" only makes sense for textual or list inputs.
     *
     * @param array<string, list<string>> $errors
     */
    protected function validateNotBlank(array &$errors, string $field, mixed $value): void
    {
        if ($value === null) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should not be blank.';
            return;
        }

        if (is_string($value) && trim($value) === '') {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should not be blank.';
            return;
        }

        if (is_array($value) && $value === []) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should not be blank.';
        }
    }

    /**
     * Mirror of {@see validateNotBlank()}: accepts the same "blank" inputs,
     * rejects any non-blank value.
     *
     * @param array<string, list<string>> $errors
     */
    protected function validateBlank(array &$errors, string $field, mixed $value): void
    {
        if ($value === null) {
            return;
        }
        if (is_string($value) && trim($value) === '') {
            return;
        }
        if (is_array($value) && $value === []) {
            return;
        }

        $errors[$field] = $errors[$field] ?? [];
        $errors[$field][] = 'This value should be blank.';
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateNotNull(array &$errors, string $field, mixed $value): void
    {
        if ($value === null) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should not be null.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateIsNull(array &$errors, string $field, mixed $value): void
    {
        if ($value !== null) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be null.';
        }
    }
}
