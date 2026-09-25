<?php

declare(strict_types=1);

namespace Semitexa\Core\Validation\Trait;

/**
 * Validation: string or array length within min/max.
 * Use in Payload::validate(): $this->validateLength('fieldName', $this->fieldName, $min, $max, $errors);
 *
 */
trait LengthValidationTrait
{
    /**
     * @param array<string, list<string>> $errors
     */
    protected function validateLength(string $fieldName, string|array $value, ?int $min, ?int $max, array &$errors): void
    {
        // Characters, not bytes — matches StringValidationTrait::validateLength().
        $len = is_string($value) ? mb_strlen($value, 'UTF-8') : count($value);
        if ($min !== null && $len < $min) {
            $errors[$fieldName] = $errors[$fieldName] ?? [];
            $errors[$fieldName][] = "Length must be at least {$min}.";
        }
        if ($max !== null && $len > $max) {
            $errors[$fieldName] = $errors[$fieldName] ?? [];
            $errors[$fieldName][] = "Length must be at most {$max}.";
        }
    }
}
