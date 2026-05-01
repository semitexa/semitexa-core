<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Exception\ValidationException;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Validation\Trait\NotBlankValidationTrait;

/**
 * Contract test for the framework's setter-time NotBlank assertion.
 *
 * The trait is the canonical reuse point for "must not be blank" checks
 * across every Payload DTO that validates at setter time. Inline helpers
 * are not allowed — the trait is the single source of truth so every
 * payload throws the same exception shape on the same input.
 */
final class NotBlankValidationTraitTest extends TestCase
{
    public function test_returns_trimmed_value_on_non_blank_input(): void
    {
        $host = self::makeHost();

        self::assertSame('hello', $host->run('field', '  hello  '));
        self::assertSame('hello',   $host->run('field', 'hello'));
        self::assertSame('a b',     $host->run('field', "\ta b\n"));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function blankInputs(): iterable
    {
        yield 'empty string'       => [''];
        yield 'single space'       => [' '];
        yield 'tabs'               => ["\t\t"];
        yield 'newlines'           => ["\n\n"];
        yield 'mixed whitespace'   => [" \t\n  "];
    }

    /**
     * @dataProvider blankInputs
     */
    public function test_throws_validation_exception_on_blank_input(string $input): void
    {
        $host = self::makeHost();

        try {
            $host->run('username', $input);
            self::fail('expected ValidationException for blank input');
        } catch (ValidationException $e) {
            self::assertSame(
                ['errors' => ['username' => ['Must not be blank.']]],
                $e->getErrorContext(),
            );
            self::assertSame(HttpStatus::UnprocessableEntity, $e->getStatusCode());
        }
    }

    public function test_custom_message_is_propagated_into_the_errors_envelope(): void
    {
        $host = self::makeHost();

        try {
            $host->run('id', '   ', 'Customer id is required.');
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(
                ['errors' => ['id' => ['Customer id is required.']]],
                $e->getErrorContext(),
            );
        }
    }

    /**
     * Build a tiny anonymous host that mixes the trait in. The trait method
     * is `protected`, so we expose a thin `run()` shim for the test.
     */
    private static function makeHost(): object
    {
        return new class () {
            use NotBlankValidationTrait;

            public function run(string $field, string $value, string $message = 'Must not be blank.'): string
            {
                return self::requireNotBlank($field, $value, $message);
            }
        };
    }
}
