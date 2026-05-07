<?php

declare(strict_types=1);

namespace Semitexa\Core\Validation\Trait;

use InvalidArgumentException;
use Semitexa\Core\Validation\Path;

/**
 * Accumulator-style collection / nested-shape validators.
 *
 * Nested errors are written under composed keys built with
 * {@see Path::join()}: a failed item validation at index 0 of a `variants`
 * field becomes `variants[0]`; a `sku` sub-field becomes
 * `variants[0].sku`.
 *
 * Item-validator callbacks share the framework convention:
 *
 *     callable(array &$errors, string $field, mixed $value): void
 *
 * Map-key validators take the same shape but receive the array key as
 * `$value`:
 *
 *     callable(array &$errors, string $field, mixed $key): void
 *
 * Conventions:
 *
 *  - `null` values are silently accepted so callers can chain
 *    {@see PresenceValidationTrait::validateOptional()}.
 *  - {@see validateCollection()} accepts a small schema shape:
 *    `['<key>' => ['required' => bool, 'validator' => callable]]`. Any
 *    missing field, malformed entry, or unknown shape key throws
 *    {@see InvalidArgumentException}; that is configuration error, not
 *    user input.
 *  - {@see validateOptionalKeys()} and {@see validateNoExtraKeys()} share
 *    the same runtime check (every key in `$value` must appear in
 *    `$allowedKeys`); they exist as two methods so callers can express
 *    intent through naming.
 */
trait CollectionValidationTrait
{
    /**
     * @param array<string, list<string>>                 $errors
     * @param array<int|string, mixed>|null               $value
     * @param callable(array<string, list<string>>&, string, mixed): void $itemValidator
     */
    protected function validateArrayOf(
        array &$errors,
        string $field,
        ?array $value,
        callable $itemValidator,
    ): void {
        if ($value === null) {
            return;
        }
        $position = 0;
        foreach ($value as $key => $item) {
            $itemValidator($errors, $this->composeItemPath($field, $key, $position), $item);
            $position++;
        }
    }

    /**
     * @param array<string, list<string>>                 $errors
     * @param array<int|string, mixed>|null               $value
     * @param callable(array<string, list<string>>&, string, mixed): void $itemValidator
     */
    protected function validateListOf(
        array &$errors,
        string $field,
        ?array $value,
        callable $itemValidator,
    ): void {
        if ($value === null) {
            return;
        }
        if (! array_is_list($value)) {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = 'This value should be a sequential list.';
            return;
        }
        foreach ($value as $index => $item) {
            $itemValidator($errors, Path::join($field, $index), $item);
        }
    }

    /**
     * @param array<string, list<string>>                 $errors
     * @param array<int|string, mixed>|null               $value
     * @param callable(array<string, list<string>>&, string, mixed): void $keyValidator
     * @param callable(array<string, list<string>>&, string, mixed): void $valueValidator
     */
    protected function validateMapOf(
        array &$errors,
        string $field,
        ?array $value,
        callable $keyValidator,
        callable $valueValidator,
    ): void {
        if ($value === null) {
            return;
        }
        $position = 0;
        foreach ($value as $key => $item) {
            $itemPath = $this->composeItemPath($field, $key, $position);
            $keyValidator($errors, $itemPath, $key);
            $valueValidator($errors, $itemPath, $item);
            $position++;
        }
    }

    /**
     * @param array<string, list<string>>                                                                                          $errors
     * @param array<int|string, mixed>|null                                                                                        $value
     * @param array<string, array{required: bool, validator: callable(array<string, list<string>>&, string, mixed): void}>        $schema
     * @throws InvalidArgumentException                                                                                            when the schema shape is malformed
     */
    protected function validateCollection(
        array &$errors,
        string $field,
        ?array $value,
        array $schema,
        bool $allowExtraFields = false,
    ): void {
        $this->guardCollectionSchema($schema);
        if ($value === null) {
            return;
        }

        foreach ($schema as $key => $definition) {
            $exists = array_key_exists($key, $value);
            if (! $exists) {
                if ($definition['required']) {
                    $errors[Path::join($field, $key)] = $errors[Path::join($field, $key)] ?? [];
                    $errors[Path::join($field, $key)][] = 'This value is required.';
                }
                continue;
            }
            $definition['validator']($errors, Path::join($field, $key), $value[$key]);
        }

        if ($allowExtraFields) {
            return;
        }
        foreach (array_keys($value) as $key) {
            if (! array_key_exists($key, $schema)) {
                $errors[Path::join($field, (string) $key)] = $errors[Path::join($field, (string) $key)] ?? [];
                $errors[Path::join($field, (string) $key)][] = 'This key is not allowed.';
            }
        }
    }

    /**
     * @param array<string, list<string>>     $errors
     * @param array<int|string, mixed>|null   $value
     * @param list<string>                    $requiredKeys
     */
    protected function validateRequiredKeys(
        array &$errors,
        string $field,
        ?array $value,
        array $requiredKeys,
    ): void {
        if ($value === null) {
            return;
        }
        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $value)) {
                $errors[Path::join($field, $key)] = $errors[Path::join($field, $key)] ?? [];
                $errors[Path::join($field, $key)][] = 'This value is required.';
            }
        }
    }

    /**
     * @param array<string, list<string>>     $errors
     * @param array<int|string, mixed>|null   $value
     * @param list<string>                    $allowedKeys
     */
    protected function validateOptionalKeys(
        array &$errors,
        string $field,
        ?array $value,
        array $allowedKeys,
    ): void {
        $this->validateNoExtraKeys($errors, $field, $value, $allowedKeys);
    }

    /**
     * @param array<string, list<string>>     $errors
     * @param array<int|string, mixed>|null   $value
     * @param list<string>                    $allowedKeys
     */
    protected function validateNoExtraKeys(
        array &$errors,
        string $field,
        ?array $value,
        array $allowedKeys,
    ): void {
        if ($value === null) {
            return;
        }
        foreach (array_keys($value) as $key) {
            if (! in_array((string) $key, $allowedKeys, true)) {
                $errors[Path::join($field, (string) $key)] = $errors[Path::join($field, (string) $key)] ?? [];
                $errors[Path::join($field, (string) $key)][] = 'This key is not allowed.';
            }
        }
    }

    /**
     * @param array<string, list<string>>     $errors
     * @param array<int|string, mixed>|null   $value
     * @param list<string>                    $keys
     * @throws InvalidArgumentException       when `$keys` is empty
     */
    protected function validateAtLeastOneKey(
        array &$errors,
        string $field,
        ?array $value,
        array $keys,
    ): void {
        if ($keys === []) {
            throw new InvalidArgumentException('At least one allowed key must be provided.');
        }
        if ($value === null) {
            return;
        }
        foreach ($keys as $key) {
            if (array_key_exists($key, $value)) {
                return;
            }
        }
        $errors[$field] = $errors[$field] ?? [];
        $errors[$field][] = 'At least one of the required keys must be present.';
    }

    /**
     * @param array<string, list<string>>     $errors
     * @param array<int|string, mixed>|null   $value
     * @param list<string>                    $keys
     * @throws InvalidArgumentException       when `$keys` is empty
     */
    protected function validateExactlyOneKey(
        array &$errors,
        string $field,
        ?array $value,
        array $keys,
    ): void {
        if ($keys === []) {
            throw new InvalidArgumentException('At least one allowed key must be provided.');
        }
        if ($value === null) {
            return;
        }
        $present = 0;
        foreach ($keys as $key) {
            if (array_key_exists($key, $value)) {
                $present++;
            }
        }
        if ($present === 1) {
            return;
        }
        $errors[$field] = $errors[$field] ?? [];
        $errors[$field][] = 'Exactly one of the listed keys must be present.';
    }

    /**
     * @param array<string, list<string>>     $errors
     * @param array<int|string, mixed>|null   $value
     * @param list<string>                    $keys
     * @throws InvalidArgumentException       when `$keys` is empty
     */
    protected function validateMutuallyExclusiveKeys(
        array &$errors,
        string $field,
        ?array $value,
        array $keys,
    ): void {
        if ($keys === []) {
            throw new InvalidArgumentException('At least one allowed key must be provided.');
        }
        if ($value === null) {
            return;
        }
        $present = 0;
        foreach ($keys as $key) {
            if (array_key_exists($key, $value)) {
                $present++;
                if ($present > 1) {
                    $errors[$field] = $errors[$field] ?? [];
                    $errors[$field][] = 'These keys are mutually exclusive.';
                    return;
                }
            }
        }
    }

    /**
     * Compose a nested validation-error key. Integer keys render as
     * `field[n]`; non-empty string keys render as `field.key`. Empty or
     * whitespace-only string keys are pathological for path composition
     * (a user-supplied JSON map could carry one) and fall back to the
     * iteration position so the error envelope still has a stable key.
     */
    private function composeItemPath(string $field, int|string $key, int $position): string
    {
        if (is_int($key)) {
            return Path::join($field, $key);
        }
        $stringKey = (string) $key;
        if (trim($stringKey) === '') {
            return Path::join($field, $position);
        }
        return Path::join($field, $stringKey);
    }

    /**
     * @param array<string, array{required: bool, validator: callable(array<string, list<string>>&, string, mixed): void}> $schema
     * @throws InvalidArgumentException
     */
    private function guardCollectionSchema(array $schema): void
    {
        if ($schema === []) {
            throw new InvalidArgumentException('Collection schema must not be empty.');
        }
        foreach ($schema as $key => $definition) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Collection schema keys must be strings.');
            }
            if (! is_array($definition) || ! array_key_exists('required', $definition) || ! array_key_exists('validator', $definition)) {
                throw new InvalidArgumentException("Schema entry '{$key}' must be ['required' => bool, 'validator' => callable].");
            }
            if (! is_bool($definition['required'])) {
                throw new InvalidArgumentException("Schema entry '{$key}': 'required' must be a boolean.");
            }
            if (! is_callable($definition['validator'])) {
                throw new InvalidArgumentException("Schema entry '{$key}': 'validator' must be callable.");
            }
        }
    }
}
