<?php

declare(strict_types=1);

namespace Semitexa\Core\Validation\Trait;

use DateTimeZone;

/**
 * Accumulator-style "domain-friendly" validators that ship without
 * external dependencies and without bundled tables of country/currency
 * codes.
 *
 * Policy notes (called out at the method that owns the choice):
 *
 *  - {@see validateCountryCode()}  — light: two uppercase ASCII letters.
 *    Does not check membership of the ISO 3166-1 alpha-2 list.
 *  - {@see validateCurrencyCode()} — light: three uppercase ASCII letters.
 *    Does not check membership of the ISO 4217 list.
 *  - {@see validateLocaleCode()}   — BCP-47-ish: a 2–3 letter language
 *    tag, optionally suffixed with `-<region>` (2–4 letters/digits).
 *  - {@see validateTimezone()}     — strict: must be present in
 *    {@see DateTimeZone::listIdentifiers()}.
 *  - {@see validateE164Phone()}    — format only (`+` then 1–15 digits,
 *    leading digit non-zero); no carrier or region check.
 *  - {@see validateHexColor()}     — `#RGB` / `#RRGGBB`, optionally
 *    `#RRGGBBAA` when `$allowAlpha` is true.
 *  - {@see validateBase64()}       — strict round-trip via
 *    `base64_decode(..., true)` + `base64_encode()` equality.
 *  - {@see validateMimeType()}     — `type/subtype` token shape only;
 *    does not validate against the IANA registry.
 *
 * Heavier checks (IBAN, BIC, VAT, ISBN, ISSN, Luhn, region-aware phone)
 * are intentionally **not** implemented in this trait — a weak fake
 * version would mislead callers. They remain available as future
 * dependency-aware extensions.
 */
trait DomainValidationTrait
{
    private const string COUNTRY_PATTERN  = '/^[A-Z]{2}$/';
    private const string CURRENCY_PATTERN = '/^[A-Z]{3}$/';
    private const string LOCALE_PATTERN   = '/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,4})?$/';
    private const string E164_PATTERN     = '/^\+[1-9]\d{1,14}$/';
    private const string MIME_PATTERN     = '/^[A-Za-z0-9!#$&^_.+-]+\/[A-Za-z0-9!#$&^_.+-]+$/';

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateCountryCode(array &$errors, string $field, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        if (preg_match(self::COUNTRY_PATTERN, $value) !== 1) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a 2-letter country code.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateCurrencyCode(array &$errors, string $field, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        if (preg_match(self::CURRENCY_PATTERN, $value) !== 1) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a 3-letter currency code.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateLocaleCode(array &$errors, string $field, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        if (preg_match(self::LOCALE_PATTERN, $value) !== 1) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a valid locale code.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateTimezone(array &$errors, string $field, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        if (! in_array($value, DateTimeZone::listIdentifiers(), true)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a valid timezone identifier.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateE164Phone(array &$errors, string $field, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        if (preg_match(self::E164_PATTERN, $value) !== 1) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a valid E.164 phone number.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateHexColor(
        array &$errors,
        string $field,
        ?string $value,
        bool $allowAlpha = true,
    ): void {
        if ($value === null) {
            return;
        }
        $pattern = $allowAlpha
            ? '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/'
            : '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';
        if (preg_match($pattern, $value) !== 1) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a valid hex color.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateBase64(array &$errors, string $field, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        $decoded = base64_decode($value, true);
        if ($decoded === false || base64_encode($decoded) !== $value) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a valid Base64 string.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateMimeType(array &$errors, string $field, ?string $value): void
    {
        if ($value === null) {
            return;
        }
        if (preg_match(self::MIME_PATTERN, $value) !== 1) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a valid MIME type.';
        }
    }
}
