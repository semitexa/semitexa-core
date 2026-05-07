<?php

declare(strict_types=1);

namespace Semitexa\Core\Validation\Trait;

use InvalidArgumentException;

/**
 * Accumulator-style string validators.
 *
 * Conventions:
 *
 *  - Null is silently accepted by every method so the caller can chain a
 *    {@see PresenceValidationTrait::validateOptional()} guard without
 *    re-checking inside each rule.
 *  - Type assertions are not repeated here. Pair these methods with
 *    {@see TypeValidationTrait::validateString()} when the field's PHP
 *    type itself is uncertain.
 *  - Length is measured in Unicode characters via `mb_strlen()` (UTF-8).
 *  - Alpha/AlphaNumeric apply an ASCII-only policy: `[A-Za-z]+` and
 *    `[A-Za-z0-9]+` respectively. Locale-aware character classes are
 *    intentionally out of scope.
 *  - {@see validateRegex()} treats an invalid pattern as a developer error
 *    and throws {@see InvalidArgumentException}.
 */
trait StringValidationTrait
{
    /**
     * Length must be within `[min, max]` (inclusive). Either bound can be
     * `null` to leave that side open.
     *
     * @param array<string, list<string>> $errors
     */
    protected function validateLength(
        array &$errors,
        string $field,
        ?string $value,
        ?int $min = null,
        ?int $max = null,
    ): void {
        if ($value === null) {
            return;
        }
        $length = mb_strlen($value);
        if ($min !== null && $length < $min) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = "This value should be at least {$min} characters.";
        }
        if ($max !== null && $length > $max) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = "This value should be at most {$max} characters.";
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateMinLength(array &$errors, string $field, ?string $value, int $min): void
    {
        if ($value === null) {
            return;
        }
        if (mb_strlen($value) < $min) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = "This value should be at least {$min} characters.";
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateMaxLength(array &$errors, string $field, ?string $value, int $max): void
    {
        if ($value === null) {
            return;
        }
        if (mb_strlen($value) > $max) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = "This value should be at most {$max} characters.";
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateExactLength(array &$errors, string $field, ?string $value, int $length): void
    {
        if ($value === null) {
            return;
        }
        if (mb_strlen($value) !== $length) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = "This value should be exactly {$length} characters.";
        }
    }

    /**
     * @param array<string, list<string>> $errors
     * @throws InvalidArgumentException when `$pattern` is not a valid PCRE pattern
     */
    protected function validateRegex(
        array &$errors,
        string $field,
        ?string $value,
        string $pattern,
    ): void {
        if ($value === null) {
            return;
        }

        $previous = error_reporting(0);
        $match    = preg_match($pattern, $value);
        error_reporting($previous);

        if ($match === false) {
            throw new InvalidArgumentException(
                "Invalid regex pattern supplied to validateRegex(): {$pattern}",
            );
        }

        if ($match === 0) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value is not in the expected format.';
        }
    }

    /**
     * ASCII-only: matches `[A-Za-z]+`.
     *
     * @param array<string, list<string>> $errors
     */
    protected function validateAlpha(array &$errors, string $field, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        if (preg_match('/^[A-Za-z]+$/', $value) !== 1) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should contain only letters.';
        }
    }

    /**
     * ASCII-only: matches `[A-Za-z0-9]+`.
     *
     * @param array<string, list<string>> $errors
     */
    protected function validateAlphaNumeric(array &$errors, string $field, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        if (preg_match('/^[A-Za-z0-9]+$/', $value) !== 1) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should contain only letters and digits.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateStartsWith(array &$errors, string $field, ?string $value, string $prefix): void
    {
        if ($value === null) {
            return;
        }
        if (! str_starts_with($value, $prefix)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = "This value should start with \"{$prefix}\".";
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateEndsWith(array &$errors, string $field, ?string $value, string $suffix): void
    {
        if ($value === null) {
            return;
        }
        if (! str_ends_with($value, $suffix)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = "This value should end with \"{$suffix}\".";
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateContains(array &$errors, string $field, ?string $value, string $needle): void
    {
        if ($value === null) {
            return;
        }
        if (! str_contains($value, $needle)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = "This value should contain \"{$needle}\".";
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateNotContains(array &$errors, string $field, ?string $value, string $needle): void
    {
        if ($value === null) {
            return;
        }
        if (str_contains($value, $needle)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = "This value should not contain \"{$needle}\".";
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateLowercase(array &$errors, string $field, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        if ($value !== mb_strtolower($value)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be in lowercase.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateUppercase(array &$errors, string $field, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        if ($value !== mb_strtoupper($value)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be in uppercase.';
        }
    }
}
