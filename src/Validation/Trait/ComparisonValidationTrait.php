<?php

declare(strict_types=1);

namespace Semitexa\Core\Validation\Trait;

/**
 * Accumulator-style equality / cross-field comparison validators.
 *
 * Conventions:
 *
 *  - **No silent skip on `null`.** Equality validators treat `null` as a
 *    meaningful comparable value: `validateEqualTo($v, null)` succeeds
 *    only when `$v === null`. Callers that want "skip when missing"
 *    semantics must guard with
 *    {@see PresenceValidationTrait::validateOptional()} themselves.
 *  - **Field-comparison messages mention only field names.** Raw rejected
 *    or expected values are never embedded in messages, since cross-field
 *    comparisons (`password` ↔ `password_confirmation`, secret tokens, …)
 *    routinely involve sensitive data.
 *  - The caller resolves the other field's value and passes both
 *    `$otherField` (for the message) and `$otherValue` (for the
 *    comparison). The trait does not reflect into `$this`.
 */
trait ComparisonValidationTrait
{
    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateEqualTo(
        array &$errors,
        string $field,
        mixed $value,
        mixed $expected,
        bool $strict = true,
    ): void {
        $matches = $strict ? $value === $expected : $value == $expected;
        if (! $matches) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should equal the expected value.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateNotEqualTo(
        array &$errors,
        string $field,
        mixed $value,
        mixed $unexpected,
        bool $strict = true,
    ): void {
        $matches = $strict ? $value === $unexpected : $value == $unexpected;
        if ($matches) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should not equal the disallowed value.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateIdenticalTo(
        array &$errors,
        string $field,
        mixed $value,
        mixed $expected,
    ): void {
        if ($value !== $expected) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be identical to the expected value.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateNotIdenticalTo(
        array &$errors,
        string $field,
        mixed $value,
        mixed $unexpected,
    ): void {
        if ($value === $unexpected) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should not be identical to the disallowed value.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateSameAsField(
        array &$errors,
        string $field,
        mixed $value,
        string $otherField,
        mixed $otherValue,
        bool $strict = true,
    ): void {
        $matches = $strict ? $value === $otherValue : $value == $otherValue;
        if (! $matches) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = "This value should match {$otherField}.";
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateDifferentFromField(
        array &$errors,
        string $field,
        mixed $value,
        string $otherField,
        mixed $otherValue,
        bool $strict = true,
    ): void {
        $matches = $strict ? $value === $otherValue : $value == $otherValue;
        if ($matches) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = "This value should differ from {$otherField}.";
        }
    }
}
