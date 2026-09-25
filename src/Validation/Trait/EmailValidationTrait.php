<?php

declare(strict_types=1);

namespace Semitexa\Core\Validation\Trait;

/**
 * Validation: string must be a valid email format (when non-empty).
 * Use in Payload::validate(): $this->validateEmail('fieldName', $this->fieldName, $errors);
 *
 */
trait EmailValidationTrait
{
    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateEmail(string $fieldName, string $value, array &$errors, string $message = 'Invalid email format.'): void
    {
        if ($value !== '' && !$this->isEmailLike($value)) {
            $errors[$fieldName] = $errors[$fieldName] ?? [];
            $errors[$fieldName][] = $message;
        }
    }

    private function isEmailLike(string $value): bool
    {
        return (bool) preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/D', $value);
    }
}
