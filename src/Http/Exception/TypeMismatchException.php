<?php

declare(strict_types=1);

namespace Semitexa\Core\Http\Exception;

use RuntimeException;

/**
 * Thrown by PayloadHydrator when strict mode is enabled and an incoming value
 * cannot be meaningfully coerced to the declared property type.
 *
 * The Application converts this into a 422 Unprocessable Entity response.
 * PayloadHydrator reads every field before it throws: the first mismatch is
 * this exception, the rest ride along in $others, and errors() gives them all
 * in one map, so a request learns about every badly typed field at once.
 */
final class TypeMismatchException extends RuntimeException
{
    /** @var list<self> the mismatches after this one in the same payload */
    private array $others = [];

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

    /**
     * The first mismatch, carrying the rest.
     *
     * @param non-empty-list<self> $mismatches
     */
    public static function all(array $mismatches): self
    {
        $first = $mismatches[0];
        $first->others = array_slice($mismatches, 1);

        return $first;
    }

    /**
     * Every badly typed field with its message, this one first.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        $errors = [];
        foreach ([$this, ...$this->others] as $mismatch) {
            $errors[$mismatch->field][] = $mismatch->getMessage();
        }

        return $errors;
    }
}
