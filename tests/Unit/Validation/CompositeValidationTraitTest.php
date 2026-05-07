<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Validation;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Validation\Trait\CompositeValidationTrait;

final class CompositeValidationTraitTest extends TestCase
{
    public function test_all_merges_errors_from_every_validator(): void
    {
        $errors = [];
        self::host()->all($errors, 'f', 'value', [
            self::failingValidator('first'),
            self::failingValidator('second'),
        ]);
        self::assertSame(['f' => ['first', 'second']], $errors);
    }

    public function test_any_of_passes_when_at_least_one_validator_passes(): void
    {
        $errors = [];
        self::host()->anyOf($errors, 'f', 'value', [
            self::failingValidator('nope'),
            self::passingValidator(),
            self::failingValidator('also-nope'),
        ]);
        self::assertSame([], $errors);
    }

    public function test_any_of_fails_when_no_validators_pass(): void
    {
        $errors = [];
        self::host()->anyOf($errors, 'f', 'value', [
            self::failingValidator('a'),
            self::failingValidator('b'),
        ]);
        self::assertSame(
            ['f' => ['This value did not satisfy any of the allowed alternatives.']],
            $errors,
        );
    }

    public function test_one_of_passes_when_exactly_one_validator_passes(): void
    {
        $errors = [];
        self::host()->oneOf($errors, 'f', 'value', [
            self::passingValidator(),
            self::failingValidator('only-other'),
        ]);
        self::assertSame([], $errors);
    }

    public function test_one_of_fails_when_zero_or_multiple_pass(): void
    {
        $errors = [];
        self::host()->oneOf($errors, 'f', 'value', [
            self::passingValidator(),
            self::passingValidator(),
        ]);
        self::assertNotEmpty($errors);

        $errors = [];
        self::host()->oneOf($errors, 'f', 'value', [
            self::failingValidator('a'),
            self::failingValidator('b'),
        ]);
        self::assertNotEmpty($errors);
    }

    public function test_none_of_passes_when_all_fail(): void
    {
        $errors = [];
        self::host()->noneOf($errors, 'f', 'value', [
            self::failingValidator('a'),
            self::failingValidator('b'),
        ]);
        self::assertSame([], $errors);
    }

    public function test_none_of_fails_when_at_least_one_passes(): void
    {
        $errors = [];
        self::host()->noneOf($errors, 'f', 'value', [
            self::failingValidator('a'),
            self::passingValidator(),
        ]);
        self::assertNotEmpty($errors);
    }

    public function test_sequentially_stops_after_first_failing_validator(): void
    {
        $secondCalls = 0;
        $second = function (array &$errors, string $f, mixed $v) use (&$secondCalls): void {
            $secondCalls++;
        };

        $errors = [];
        self::host()->sequentially($errors, 'f', 'value', [
            self::failingValidator('first'),
            $second,
        ]);
        self::assertSame(['f' => ['first']], $errors);
        self::assertSame(0, $secondCalls);
    }

    public function test_sequentially_runs_all_when_each_passes(): void
    {
        $callCount = 0;
        $passing = function (array &$errors, string $f, mixed $v) use (&$callCount): void {
            $callCount++;
        };

        $errors = [];
        self::host()->sequentially($errors, 'f', 'value', [$passing, $passing, $passing]);
        self::assertSame([], $errors);
        self::assertSame(3, $callCount);
    }

    public function test_inner_messages_do_not_leak_through_anyof(): void
    {
        $errors = [];
        self::host()->anyOf($errors, 'f', 'value', [
            self::failingValidator('SECRET-INTERNAL-MESSAGE'),
        ]);
        self::assertCount(1, $errors['f']);
        self::assertStringNotContainsString('SECRET-INTERNAL-MESSAGE', $errors['f'][0]);
    }

    public function test_empty_validator_list_throws(): void
    {
        $errors = [];
        $this->expectException(InvalidArgumentException::class);
        self::host()->all($errors, 'f', 'value', []);
    }

    public function test_non_callable_entry_throws(): void
    {
        $errors = [];
        $this->expectException(InvalidArgumentException::class);
        self::host()->all($errors, 'f', 'value', ['not-callable']);
    }

    private static function passingValidator(): callable
    {
        return static function (array &$errors, string $field, mixed $value): void {};
    }

    private static function failingValidator(string $message): callable
    {
        return static function (array &$errors, string $field, mixed $value) use ($message): void {
            $errors[$field] = $errors[$field] ?? [];
            $errors[$field][] = $message;
        };
    }

    private static function host(): object
    {
        return new class () {
            use CompositeValidationTrait;
            /** @param array<string, list<string>> $errors */
            public function all(array &$errors, string $f, mixed $v, array $vs): void
            { $this->validateAll($errors, $f, $v, $vs); }
            /** @param array<string, list<string>> $errors */
            public function anyOf(array &$errors, string $f, mixed $v, array $vs): void
            { $this->validateAnyOf($errors, $f, $v, $vs); }
            /** @param array<string, list<string>> $errors */
            public function oneOf(array &$errors, string $f, mixed $v, array $vs): void
            { $this->validateOneOf($errors, $f, $v, $vs); }
            /** @param array<string, list<string>> $errors */
            public function noneOf(array &$errors, string $f, mixed $v, array $vs): void
            { $this->validateNoneOf($errors, $f, $v, $vs); }
            /** @param array<string, list<string>> $errors */
            public function sequentially(array &$errors, string $f, mixed $v, array $vs): void
            { $this->validateSequentially($errors, $f, $v, $vs); }
        };
    }
}
