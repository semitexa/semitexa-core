<?php

declare(strict_types=1);

namespace Semitexa\Core\Validation\Trait;

use Semitexa\Core\Exception\ValidationException;

/**
 * Setter-time "must not be blank" assertion.
 *
 * Drop the trait into a Payload DTO (or any object whose setters validate
 * at assignment time) and call `self::requireNotBlank('field', $value)` —
 * or `$this->requireNotBlank(...)` — from the relevant `setX()`. Returns
 * the trimmed value on success; throws
 * `Semitexa\Core\Exception\ValidationException` keyed by `$field` on a
 * blank input. The framework converts the exception to a 422 response with
 * a `{ errors: { field: [...] } }` envelope (HTTP path) or to a
 * `VALIDATION_FAILED` GraphQL error (GraphQL transport path).
 *
 * Example:
 *
 *     use Semitexa\Core\Validation\Trait\NotBlankValidationTrait;
 *
 *     final class CreateUserPayload
 *     {
 *         use NotBlankValidationTrait;
 *
 *         private string $name = '';
 *
 *         public function setName(string $name): void
 *         {
 *             $this->name = self::requireNotBlank('name', $name);
 *         }
 *     }
 */
trait NotBlankValidationTrait
{
    /**
     * @throws ValidationException when the trimmed value is empty.
     */
    protected static function requireNotBlank(
        string $field,
        string $value,
        string $message = 'Must not be blank.',
    ): string {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new ValidationException([$field => [$message]]);
        }
        return $trimmed;
    }
}
