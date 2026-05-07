<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Validation;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Validation\Trait\NumericValidationTrait;

final class NumericValidationTraitTest extends TestCase
{
    public function test_positive_and_positive_or_zero(): void
    {
        $h = self::host();

        $errors = [];
        $h->positive($errors, 'a', 1);
        self::assertSame([], $errors);

        $errors = [];
        $h->positive($errors, 'a', 0);
        self::assertSame(['a' => ['This value should be greater than 0.']], $errors);

        $errors = [];
        $h->positiveOrZero($errors, 'b', 0);
        self::assertSame([], $errors);

        $errors = [];
        $h->positiveOrZero($errors, 'b', -1);
        self::assertSame(['b' => ['This value should be greater than or equal to 0.']], $errors);
    }

    public function test_negative_and_negative_or_zero(): void
    {
        $h = self::host();

        $errors = [];
        $h->negative($errors, 'a', -1);
        self::assertSame([], $errors);

        $errors = [];
        $h->negative($errors, 'a', 0);
        self::assertSame(['a' => ['This value should be less than 0.']], $errors);

        $errors = [];
        $h->negativeOrZero($errors, 'b', 0);
        self::assertSame([], $errors);

        $errors = [];
        $h->negativeOrZero($errors, 'b', 1);
        self::assertSame(['b' => ['This value should be less than or equal to 0.']], $errors);
    }

    public function test_greater_less_boundaries(): void
    {
        $h = self::host();

        $errors = [];
        $h->greaterThan($errors, 'a', 5, 5);
        self::assertNotEmpty($errors);

        $errors = [];
        $h->greaterThanOrEqual($errors, 'a', 5, 5);
        self::assertSame([], $errors);

        $errors = [];
        $h->lessThan($errors, 'a', 5, 5);
        self::assertNotEmpty($errors);

        $errors = [];
        $h->lessThanOrEqual($errors, 'a', 5, 5);
        self::assertSame([], $errors);
    }

    public function test_range_min_max(): void
    {
        $h = self::host();

        $errors = [];
        $h->range($errors, 'a', 5, 1, 10);
        self::assertSame([], $errors);

        $errors = [];
        $h->range($errors, 'a', 0, 1, 10);
        self::assertSame(['a' => ['This value should be greater than or equal to 1.']], $errors);

        $errors = [];
        $h->range($errors, 'a', 11, 1, 10);
        self::assertSame(['a' => ['This value should be less than or equal to 10.']], $errors);

        $errors = [];
        $h->range($errors, 'a', -100, null, 10);
        self::assertSame([], $errors);

        $errors = [];
        $h->range($errors, 'a', 100, 1, null);
        self::assertSame([], $errors);
    }

    public function test_divisible_by_int(): void
    {
        $h = self::host();

        $errors = [];
        $h->divisibleBy($errors, 'a', 10, 2);
        self::assertSame([], $errors);

        $errors = [];
        $h->divisibleBy($errors, 'a', 11, 2);
        self::assertSame(['a' => ['This value should be divisible by 2.']], $errors);
    }

    public function test_multiple_of_float_tolerance(): void
    {
        $h = self::host();

        $errors = [];
        $h->multipleOf($errors, 'a', 0.3, 0.1);
        self::assertSame([], $errors);

        $errors = [];
        $h->multipleOf($errors, 'a', 0.31, 0.1);
        self::assertNotEmpty($errors);
    }

    public function test_zero_divisor_throws(): void
    {
        $errors = [];
        $this->expectException(InvalidArgumentException::class);
        self::host()->divisibleBy($errors, 'a', 1, 0);
    }

    public function test_zero_factor_throws(): void
    {
        $errors = [];
        $this->expectException(InvalidArgumentException::class);
        self::host()->multipleOf($errors, 'a', 1, 0.0);
    }

    public function test_null_is_silently_accepted_by_every_method(): void
    {
        $errors = [];
        $h = self::host();

        $h->positive($errors, 'a', null);
        $h->positiveOrZero($errors, 'b', null);
        $h->negative($errors, 'c', null);
        $h->negativeOrZero($errors, 'd', null);
        $h->greaterThan($errors, 'e', null, 0);
        $h->greaterThanOrEqual($errors, 'f', null, 0);
        $h->lessThan($errors, 'g', null, 0);
        $h->lessThanOrEqual($errors, 'h', null, 0);
        $h->range($errors, 'i', null, 1, 10);
        $h->divisibleBy($errors, 'j', null, 2);
        $h->multipleOf($errors, 'k', null, 0.1);

        self::assertSame([], $errors);
    }

    private static function host(): object
    {
        return new class () {
            use NumericValidationTrait;
            /** @param array<string, list<string>> $errors */
            public function positive(array &$errors, string $f, int|float|null $v): void
            { $this->validatePositive($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function positiveOrZero(array &$errors, string $f, int|float|null $v): void
            { $this->validatePositiveOrZero($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function negative(array &$errors, string $f, int|float|null $v): void
            { $this->validateNegative($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function negativeOrZero(array &$errors, string $f, int|float|null $v): void
            { $this->validateNegativeOrZero($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function greaterThan(array &$errors, string $f, int|float|null $v, int|float $t): void
            { $this->validateGreaterThan($errors, $f, $v, $t); }
            /** @param array<string, list<string>> $errors */
            public function greaterThanOrEqual(array &$errors, string $f, int|float|null $v, int|float $t): void
            { $this->validateGreaterThanOrEqual($errors, $f, $v, $t); }
            /** @param array<string, list<string>> $errors */
            public function lessThan(array &$errors, string $f, int|float|null $v, int|float $t): void
            { $this->validateLessThan($errors, $f, $v, $t); }
            /** @param array<string, list<string>> $errors */
            public function lessThanOrEqual(array &$errors, string $f, int|float|null $v, int|float $t): void
            { $this->validateLessThanOrEqual($errors, $f, $v, $t); }
            /** @param array<string, list<string>> $errors */
            public function range(array &$errors, string $f, int|float|null $v, int|float|null $min, int|float|null $max): void
            { $this->validateRange($errors, $f, $v, $min, $max); }
            /** @param array<string, list<string>> $errors */
            public function divisibleBy(array &$errors, string $f, int|float|null $v, int|float $d): void
            { $this->validateDivisibleBy($errors, $f, $v, $d); }
            /** @param array<string, list<string>> $errors */
            public function multipleOf(array &$errors, string $f, int|float|null $v, int|float $m): void
            { $this->validateMultipleOf($errors, $f, $v, $m); }
        };
    }
}
