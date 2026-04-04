<?php

declare(strict_types=1);

namespace Semitexa\Core\Http\Exception;

use RuntimeException;

/**
 * Thrown by PayloadHydrator when strict mode is enabled and an incoming value
 * cannot be meaningfully coerced to the declared property type.
 *
 * The Application converts this into a 422 Unprocessable Entity response.
 */
final class TypeMismatchException extends RuntimeException
{
    public function __construct(
        public readonly string $field,
        public readonly string $expectedType,
        public readonly mixed $receivedValue,
    ) {
        $received = is_array($receivedValue) ? 'array' : gettype($receivedValue);
        parent::__construct(
            "Field '{$field}': expected {$expectedType}, received {$received}."
        );
    }
}
