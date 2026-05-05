<?php

declare(strict_types=1);

namespace Semitexa\Core\Validation\Trait;

/**
 * Accumulator-style format validators.
 *
 * Each method silently accepts `null` so the caller can chain
 * {@see PresenceValidationTrait::validateOptional()} without re-checking.
 *
 * Format policies (documented at the method that owns the choice):
 *
 *  - {@see validateEmail()}    — practical, lenient regex; matches the
 *    legacy {@see EmailValidationTrait}. Suited to user-facing forms.
 *  - {@see validateRfcEmail()} — `filter_var(FILTER_VALIDATE_EMAIL)`,
 *    closer to RFC 5321 + 5322 addr-spec.
 *  - {@see validateUrl()}      — `filter_var(FILTER_VALIDATE_URL)`.
 *  - {@see validateUuid()}     — RFC 4122 textual form, versions 1–8,
 *    case-insensitive.
 *  - {@see validateUlid()}     — canonical 26-char Crockford Base32,
 *    case-insensitive (alphabet excludes I, L, O, U).
 *  - {@see validateIp()}       — `filter_var(FILTER_VALIDATE_IP)` with
 *    optional flags (e.g. `FILTER_FLAG_IPV4`).
 *  - {@see validateHostname()} — RFC 1123 host: ≤253 chars, labels
 *    1–63 chars, alphanumerics + hyphen, no leading/trailing hyphen.
 *  - {@see validateJsonString()} — `json_decode` round-trip with
 *    `json_last_error() === JSON_ERROR_NONE`.
 *  - {@see validateSlug()}     — lowercase ASCII letters and digits
 *    separated by single hyphens; no leading/trailing hyphen, no
 *    consecutive hyphens. Pattern: `^[a-z0-9]+(?:-[a-z0-9]+)*$`.
 *
 * The legacy {@see EmailValidationTrait} stays in place untouched. Its
 * `validateEmail` and the one defined here have different signatures and
 * cannot be composed into the same host class without aliasing.
 */
trait FormatValidationTrait
{
    private const string UUID_PATTERN  = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
    private const string ULID_PATTERN  = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/i';
    private const string SLUG_PATTERN  = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    private const string EMAIL_LENIENT = '/^[^@\s]+@[^@\s]+\.[^@\s]+$/';

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateEmail(array &$errors, string $field, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        if (preg_match(self::EMAIL_LENIENT, $value) !== 1) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a valid email address.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateRfcEmail(array &$errors, string $field, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a valid email address.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateUrl(array &$errors, string $field, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a valid URL.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateUuid(array &$errors, string $field, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        if (preg_match(self::UUID_PATTERN, $value) !== 1) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a valid UUID.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateUlid(array &$errors, string $field, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        if (preg_match(self::ULID_PATTERN, $value) !== 1) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a valid ULID.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateIp(array &$errors, string $field, ?string $value, ?int $flags = null): void
    {
        if ($value === null) {
            return;
        }
        $valid = $flags === null
            ? filter_var($value, FILTER_VALIDATE_IP) !== false
            : filter_var($value, FILTER_VALIDATE_IP, $flags) !== false;

        if (! $valid) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a valid IP address.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateHostname(array &$errors, string $field, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        if ($this->isHostname($value)) {
            return;
        }
        $errors[$field] = $errors[$field] ?? [];
        $errors[$field][] = 'This value should be a valid hostname.';
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateJsonString(array &$errors, string $field, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        if ($value === '') {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a valid JSON string.';
            return;
        }
        json_decode($value);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a valid JSON string.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateSlug(array &$errors, string $field, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        if (preg_match(self::SLUG_PATTERN, $value) !== 1) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a valid slug.';
        }
    }

    private function isHostname(string $value): bool
    {
        if ($value === '' || strlen($value) > 253) {
            return false;
        }

        $labels = explode('.', $value);
        foreach ($labels as $label) {
            $length = strlen($label);
            if ($length === 0 || $length > 63) {
                return false;
            }
            if (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $label) !== 1) {
                return false;
            }
        }

        return true;
    }
}
