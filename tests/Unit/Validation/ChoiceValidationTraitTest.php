<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Validation;

use ArrayObject;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Validation\Trait\ChoiceValidationTrait;
use stdClass;

final class ChoiceValidationTraitTest extends TestCase
{
    public function test_choice_strict_valid_and_invalid(): void
    {
        $errors = [];
        $h = self::host();
        $h->choice($errors, 'a', 'red', ['red', 'green', 'blue']);
        self::assertSame([], $errors);

        $h->choice($errors, 'b', 'yellow', ['red', 'green', 'blue']);
        self::assertSame(['b' => ['This value is not one of the allowed choices.']], $errors);
    }

    public function test_choice_non_strict_compares_loosely(): void
    {
        $errors = [];
        $h = self::host();
        $h->choice($errors, 'a', '1', [1, 2, 3], strict: false);
        self::assertSame([], $errors);

        $h->choice($errors, 'b', '1', [1, 2, 3], strict: true);
        self::assertNotEmpty($errors['b']);
    }

    public function test_choice_empty_list_throws(): void
    {
        $errors = [];
        $this->expectException(InvalidArgumentException::class);
        self::host()->choice($errors, 'f', 'x', []);
    }

    public function test_not_in(): void
    {
        $errors = [];
        $h = self::host();
        $h->notIn($errors, 'a', 'eve', ['admin', 'root']);
        self::assertSame([], $errors);

        $h->notIn($errors, 'b', 'admin', ['admin', 'root']);
        self::assertSame(['b' => ['This value is not allowed.']], $errors);
    }

    public function test_count_min_max(): void
    {
        $errors = [];
        $h = self::host();
        $h->count($errors, 'a', [1, 2, 3], 2, 5);
        self::assertSame([], $errors);

        $h->count($errors, 'b', [1], 2, 5);
        self::assertNotEmpty($errors['b']);

        $h->count($errors, 'c', [1, 2, 3, 4, 5, 6], 2, 5);
        self::assertNotEmpty($errors['c']);
    }

    public function test_count_with_countable(): void
    {
        $errors = [];
        self::host()->count($errors, 'a', new ArrayObject([1, 2]), 1, 3);
        self::assertSame([], $errors);
    }

    public function test_min_max_exact_count(): void
    {
        $errors = [];
        $h = self::host();
        $h->minCount($errors, 'a', [1], 2);
        self::assertNotEmpty($errors);

        $errors = [];
        $h->maxCount($errors, 'a', [1, 2, 3], 2);
        self::assertNotEmpty($errors);

        $errors = [];
        $h->exactCount($errors, 'a', [1, 2], 2);
        self::assertSame([], $errors);

        $errors = [];
        $h->exactCount($errors, 'a', [1, 2, 3], 2);
        self::assertNotEmpty($errors);
    }

    public function test_negative_count_bound_throws(): void
    {
        $errors = [];
        $this->expectException(InvalidArgumentException::class);
        self::host()->count($errors, 'f', [1, 2], -1, null);
    }

    public function test_unique_scalar_list(): void
    {
        $errors = [];
        $h = self::host();
        $h->unique($errors, 'a', ['a', 'b', 'c']);
        self::assertSame([], $errors);

        $h->unique($errors, 'b', ['a', 'b', 'a']);
        self::assertSame(['b' => ['This collection should contain only unique values.']], $errors);
    }

    public function test_unique_with_objects_throws(): void
    {
        $errors = [];
        $this->expectException(InvalidArgumentException::class);
        self::host()->unique($errors, 'f', [new stdClass(), new stdClass()]);
    }

    public function test_enum_choice_valid_and_invalid(): void
    {
        $errors = [];
        $h = self::host();
        $h->enumChoice($errors, 'a', ChoiceTestEnum::Red, ChoiceTestEnum::class);
        self::assertSame([], $errors);

        $h->enumChoice($errors, 'b', 'red', ChoiceTestEnum::class);
        self::assertNotEmpty($errors['b']);
    }

    public function test_enum_choice_non_enum_class_throws(): void
    {
        $errors = [];
        $this->expectException(InvalidArgumentException::class);
        self::host()->enumChoice($errors, 'f', 'x', stdClass::class);
    }

    public function test_backed_enum_choice_valid_and_invalid(): void
    {
        $errors = [];
        $h = self::host();
        $h->backedEnumChoice($errors, 'a', 'red', ChoiceTestEnum::class);
        self::assertSame([], $errors);

        $h->backedEnumChoice($errors, 'b', 'magenta', ChoiceTestEnum::class);
        self::assertNotEmpty($errors['b']);

        $h->backedEnumChoice($errors, 'c', 1.5, ChoiceTestEnum::class);
        self::assertNotEmpty($errors['c']);
    }

    public function test_null_is_silently_accepted(): void
    {
        $errors = [];
        $h = self::host();
        $h->choice($errors, 'a', null, ['x']);
        $h->notIn($errors, 'b', null, ['x']);
        $h->count($errors, 'c', null, 1, 2);
        $h->unique($errors, 'd', null);
        $h->enumChoice($errors, 'e', null, ChoiceTestEnum::class);
        $h->backedEnumChoice($errors, 'f', null, ChoiceTestEnum::class);
        self::assertSame([], $errors);
    }

    private static function host(): object
    {
        return new class () {
            use ChoiceValidationTrait;
            /** @param array<string, list<string>> $errors */
            public function choice(array &$errors, string $f, mixed $v, array $c, bool $strict = true): void
            { $this->validateChoice($errors, $f, $v, $c, $strict); }
            /** @param array<string, list<string>> $errors */
            public function notIn(array &$errors, string $f, mixed $v, array $d, bool $strict = true): void
            { $this->validateNotIn($errors, $f, $v, $d, $strict); }
            /** @param array<string, list<string>> $errors */
            public function count(array &$errors, string $f, \Countable|array|null $v, ?int $min = null, ?int $max = null): void
            { $this->validateCount($errors, $f, $v, $min, $max); }
            /** @param array<string, list<string>> $errors */
            public function minCount(array &$errors, string $f, \Countable|array|null $v, int $min): void
            { $this->validateMinCount($errors, $f, $v, $min); }
            /** @param array<string, list<string>> $errors */
            public function maxCount(array &$errors, string $f, \Countable|array|null $v, int $max): void
            { $this->validateMaxCount($errors, $f, $v, $max); }
            /** @param array<string, list<string>> $errors */
            public function exactCount(array &$errors, string $f, \Countable|array|null $v, int $count): void
            { $this->validateExactCount($errors, $f, $v, $count); }
            /** @param array<string, list<string>> $errors */
            public function unique(array &$errors, string $f, ?array $v, bool $strict = true): void
            { $this->validateUnique($errors, $f, $v, $strict); }
            /**
             * @param array<string, list<string>> $errors
             * @param class-string                $enumClass
             */
            public function enumChoice(array &$errors, string $f, mixed $v, string $enumClass): void
            { $this->validateEnumChoice($errors, $f, $v, $enumClass); }
            /**
             * @param array<string, list<string>> $errors
             * @param class-string                $enumClass
             */
            public function backedEnumChoice(array &$errors, string $f, mixed $v, string $enumClass): void
            { $this->validateBackedEnumChoice($errors, $f, $v, $enumClass); }
        };
    }
}

enum ChoiceTestEnum: string
{
    case Red   = 'red';
    case Green = 'green';
    case Blue  = 'blue';
}
