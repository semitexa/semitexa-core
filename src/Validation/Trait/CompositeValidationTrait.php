<?php

declare(strict_types=1);

namespace Semitexa\Core\Validation\Trait;

use InvalidArgumentException;

/**
 * Accumulator-style composite validators.
 *
 * Each composite takes `array $validators`, where every entry is a
 * canonical accumulator callback:
 *
 *     callable(array &$errors, string $field, mixed $value): void
 *
 * Error surfacing strategy:
 *
 *  - {@see validateAll()} and {@see validateSequentially()} let inner
 *    validators write directly to `$errors` so the failing rule's exact
 *    message is preserved.
 *  - {@see validateAnyOf()} / {@see validateOneOf()} / {@see validateNoneOf()}
 *    run each branch against an isolated scratch buffer and surface only
 *    a single, stable, field-level message on failure. Inner messages
 *    would leak which alternative the caller chose; the public envelope
 *    stays minimal.
 *  - An empty `$validators` list is a developer error and throws
 *    {@see InvalidArgumentException}.
 */
trait CompositeValidationTrait
{
    /**
     * @param array<string, list<string>>                                            $errors
     * @param array<int, callable(array<string, list<string>>&, string, mixed): void> $validators
     * @throws InvalidArgumentException                                              when `$validators` is empty
     */
    protected function validateAll(
        array &$errors,
        string $field,
        mixed $value,
        array $validators,
    ): void {
        $this->guardValidatorList($validators);
        foreach ($validators as $validator) {
            $validator($errors, $field, $value);
        }
    }

    /**
     * @param array<string, list<string>>                                            $errors
     * @param array<int, callable(array<string, list<string>>&, string, mixed): void> $validators
     * @throws InvalidArgumentException                                              when `$validators` is empty
     */
    protected function validateSequentially(
        array &$errors,
        string $field,
        mixed $value,
        array $validators,
    ): void {
        $this->guardValidatorList($validators);
        $before = $errors[$field] ?? [];
        foreach ($validators as $validator) {
            $validator($errors, $field, $value);
            if (($errors[$field] ?? []) !== $before) {
                return;
            }
        }
    }

    /**
     * @param array<string, list<string>>                                            $errors
     * @param array<int, callable(array<string, list<string>>&, string, mixed): void> $validators
     * @throws InvalidArgumentException                                              when `$validators` is empty
     */
    protected function validateAnyOf(
        array &$errors,
        string $field,
        mixed $value,
        array $validators,
    ): void {
        if ($this->countPasses($validators, $field, $value) >= 1) {
            return;
        }
        $errors[$field] = $errors[$field] ?? [];
        $errors[$field][] = 'This value did not satisfy any of the allowed alternatives.';
    }

    /**
     * @param array<string, list<string>>                                            $errors
     * @param array<int, callable(array<string, list<string>>&, string, mixed): void> $validators
     * @throws InvalidArgumentException                                              when `$validators` is empty
     */
    protected function validateOneOf(
        array &$errors,
        string $field,
        mixed $value,
        array $validators,
    ): void {
        if ($this->countPasses($validators, $field, $value) === 1) {
            return;
        }
        $errors[$field] = $errors[$field] ?? [];
        $errors[$field][] = 'This value should satisfy exactly one of the allowed alternatives.';
    }

    /**
     * @param array<string, list<string>>                                            $errors
     * @param array<int, callable(array<string, list<string>>&, string, mixed): void> $validators
     * @throws InvalidArgumentException                                              when `$validators` is empty
     */
    protected function validateNoneOf(
        array &$errors,
        string $field,
        mixed $value,
        array $validators,
    ): void {
        if ($this->countPasses($validators, $field, $value) === 0) {
            return;
        }
        $errors[$field] = $errors[$field] ?? [];
        $errors[$field][] = 'This value should not satisfy any of the disallowed alternatives.';
    }

    /**
     * @param array<int, callable(array<string, list<string>>&, string, mixed): void> $validators
     */
    private function countPasses(array $validators, string $field, mixed $value): int
    {
        $this->guardValidatorList($validators);
        $passes = 0;
        foreach ($validators as $validator) {
            $scratch = [];
            $validator($scratch, $field, $value);
            if ($scratch === []) {
                $passes++;
            }
        }
        return $passes;
    }

    /**
     * @param array<int, callable(array<string, list<string>>&, string, mixed): void> $validators
     */
    private function guardValidatorList(array $validators): void
    {
        if ($validators === []) {
            throw new InvalidArgumentException('Composite validator list must not be empty.');
        }
        foreach ($validators as $index => $validator) {
            if (! is_callable($validator)) {
                throw new InvalidArgumentException("Validator at index {$index} is not callable.");
            }
        }
    }
}
