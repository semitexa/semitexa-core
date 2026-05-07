<?php

declare(strict_types=1);

namespace Semitexa\Core\Validation\Trait;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;

/**
 * Accumulator-style date / time validators.
 *
 * Conventions:
 *
 *  - `null` values are silently accepted so callers can chain
 *    {@see PresenceValidationTrait::validateOptional()}.
 *  - Date / time parsing is **strict round-trip**: a value is valid only
 *    when `DateTimeImmutable::createFromFormat($format, $value)` succeeds,
 *    `DateTimeImmutable::getLastErrors()` reports zero warnings and zero
 *    errors, and the parsed instance reformatted with the same `$format`
 *    matches the original input. This catches `2025-02-30` (parses as
 *    "2025-03-02" but does not round-trip) and other implicit corrections.
 *  - Threshold inputs to {@see validateBefore()} / {@see validateAfter()}
 *    are developer-supplied. An unparseable threshold throws
 *    {@see InvalidArgumentException}; an unparseable value adds a
 *    validation error.
 *  - {@see validatePast()} / {@see validateFuture()} accept an injected
 *    `$now` for deterministic testing; passing `null` resolves to
 *    `new DateTimeImmutable()`.
 *  - Timezone handling: when a string lacks an explicit zone (`Y-m-d`,
 *    `H:i:s`), parsing uses PHP's default timezone. ISO-8601 / `DATE_ATOM`
 *    strings carry their own offset and are honored as-is.
 */
trait DateTimeValidationTrait
{
    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateDate(
        array &$errors,
        string $field,
        ?string $value,
        string $format = 'Y-m-d',
    ): void {
        if ($value === null) {
            return;
        }
        if (! $this->isStrictDateTimeMatch($value, $format)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a valid date.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateDateTime(
        array &$errors,
        string $field,
        ?string $value,
        string $format = DATE_ATOM,
    ): void {
        if ($value === null) {
            return;
        }
        if (! $this->isStrictDateTimeMatch($value, $format)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a valid datetime.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateTime(
        array &$errors,
        string $field,
        ?string $value,
        string $format = 'H:i:s',
    ): void {
        if ($value === null) {
            return;
        }
        if (! $this->isStrictDateTimeMatch($value, $format)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a valid time.';
        }
    }

    /**
     * @param array<string, list<string>> $errors
     * @throws InvalidArgumentException when `$threshold` cannot be parsed
     */
    protected function validateBefore(
        array &$errors,
        string $field,
        DateTimeInterface|string|null $value,
        DateTimeInterface|string $threshold,
    ): void {
        $this->compareTemporally($errors, $field, $value, $threshold, 'before');
    }

    /**
     * @param array<string, list<string>> $errors
     * @throws InvalidArgumentException when `$threshold` cannot be parsed
     */
    protected function validateBeforeOrEqual(
        array &$errors,
        string $field,
        DateTimeInterface|string|null $value,
        DateTimeInterface|string $threshold,
    ): void {
        $this->compareTemporally($errors, $field, $value, $threshold, 'beforeOrEqual');
    }

    /**
     * @param array<string, list<string>> $errors
     * @throws InvalidArgumentException when `$threshold` cannot be parsed
     */
    protected function validateAfter(
        array &$errors,
        string $field,
        DateTimeInterface|string|null $value,
        DateTimeInterface|string $threshold,
    ): void {
        $this->compareTemporally($errors, $field, $value, $threshold, 'after');
    }

    /**
     * @param array<string, list<string>> $errors
     * @throws InvalidArgumentException when `$threshold` cannot be parsed
     */
    protected function validateAfterOrEqual(
        array &$errors,
        string $field,
        DateTimeInterface|string|null $value,
        DateTimeInterface|string $threshold,
    ): void {
        $this->compareTemporally($errors, $field, $value, $threshold, 'afterOrEqual');
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validatePast(
        array &$errors,
        string $field,
        DateTimeInterface|string|null $value,
        ?DateTimeInterface $now = null,
    ): void {
        $this->compareTemporally($errors, $field, $value, $now ?? new DateTimeImmutable(), 'before', 'past');
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validatePastOrPresent(
        array &$errors,
        string $field,
        DateTimeInterface|string|null $value,
        ?DateTimeInterface $now = null,
    ): void {
        $this->compareTemporally($errors, $field, $value, $now ?? new DateTimeImmutable(), 'beforeOrEqual', 'pastOrPresent');
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateFuture(
        array &$errors,
        string $field,
        DateTimeInterface|string|null $value,
        ?DateTimeInterface $now = null,
    ): void {
        $this->compareTemporally($errors, $field, $value, $now ?? new DateTimeImmutable(), 'after', 'future');
    }

    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateFutureOrPresent(
        array &$errors,
        string $field,
        DateTimeInterface|string|null $value,
        ?DateTimeInterface $now = null,
    ): void {
        $this->compareTemporally($errors, $field, $value, $now ?? new DateTimeImmutable(), 'afterOrEqual', 'futureOrPresent');
    }

    private function isStrictDateTimeMatch(string $value, string $format): bool
    {
        $parsed = DateTimeImmutable::createFromFormat($format, $value);
        if ($parsed === false) {
            return false;
        }
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
            return false;
        }
        return $parsed->format($format) === $value;
    }

    /**
     * @param array<string, list<string>> $errors
     * @throws InvalidArgumentException when `$threshold` is an unparseable string
     */
    private function compareTemporally(
        array &$errors,
        string $field,
        DateTimeInterface|string|null $value,
        DateTimeInterface|string $threshold,
        string $mode,
        ?string $messageMode = null,
    ): void {
        if ($value === null) {
            return;
        }

        $thresholdInstance = $this->coerceDeveloperThreshold($threshold);
        $valueInstance     = $this->coerceUserDateTime($value);

        if ($valueInstance === null) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a valid datetime.';
            return;
        }

        $passes = match ($mode) {
            'before'        => $valueInstance < $thresholdInstance,
            'beforeOrEqual' => $valueInstance <= $thresholdInstance,
            'after'         => $valueInstance > $thresholdInstance,
            'afterOrEqual'  => $valueInstance >= $thresholdInstance,
        };

        if ($passes) {
            return;
        }

        $errors[$field] = $errors[$field] ?? [];
        $errors[$field][] = match ($messageMode ?? $mode) {
            'before'          => 'This value should be earlier than the threshold.',
            'beforeOrEqual'   => 'This value should be earlier than or equal to the threshold.',
            'after'           => 'This value should be later than the threshold.',
            'afterOrEqual'    => 'This value should be later than or equal to the threshold.',
            'past'            => 'This value should be in the past.',
            'pastOrPresent'   => 'This value should be in the past or the present.',
            'future'          => 'This value should be in the future.',
            'futureOrPresent' => 'This value should be in the future or the present.',
        };
    }

    /**
     * @throws InvalidArgumentException when the threshold string is not parseable
     */
    private function coerceDeveloperThreshold(DateTimeInterface|string $threshold): DateTimeInterface
    {
        if ($threshold instanceof DateTimeInterface) {
            return $threshold;
        }
        try {
            return new DateTimeImmutable($threshold);
        } catch (Exception $e) {
            throw new InvalidArgumentException(
                "Validation threshold '{$threshold}' is not a valid datetime string.",
                previous: $e,
            );
        }
    }

    private function coerceUserDateTime(DateTimeInterface|string $value): ?DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
