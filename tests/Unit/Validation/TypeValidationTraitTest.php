<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Validation;

use ArrayIterator;
use ArrayObject;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Validation\Trait\TypeValidationTrait;
use stdClass;

final class TypeValidationTraitTest extends TestCase
{
    /**
     * @return iterable<string, array{0: mixed, 1: bool}>
     */
    public static function stringCases(): iterable
    {
        yield 'string'         => ['hi', true];
        yield 'empty string'   => ['',   true];
        yield 'integer'        => [1,    false];
        yield 'numeric string' => ['42', true];
        yield 'null'           => [null, false];
        yield 'array'          => [[],   false];
    }

    /** @dataProvider stringCases */
    public function test_string(mixed $value, bool $valid): void
    {
        self::assertSame(
            $valid ? [] : ['f' => ['This value should be a string.']],
            self::evaluate('string', $value),
        );
    }

    public function test_integer_rejects_numeric_string(): void
    {
        self::assertSame(
            ['f' => ['This value should be an integer.']],
            self::evaluate('integer', '42'),
        );
        self::assertSame([], self::evaluate('integer', 42));
    }

    public function test_float_rejects_int(): void
    {
        self::assertSame(
            ['f' => ['This value should be a float.']],
            self::evaluate('float', 1),
        );
        self::assertSame([], self::evaluate('float', 1.5));
    }

    public function test_number_accepts_int_and_float(): void
    {
        self::assertSame([], self::evaluate('number', 1));
        self::assertSame([], self::evaluate('number', 1.5));
        self::assertSame(
            ['f' => ['This value should be a number.']],
            self::evaluate('number', '1'),
        );
    }

    public function test_boolean(): void
    {
        self::assertSame([], self::evaluate('boolean', true));
        self::assertSame([], self::evaluate('boolean', false));
        self::assertSame(
            ['f' => ['This value should be a boolean.']],
            self::evaluate('boolean', 0),
        );
    }

    public function test_array_object_iterable(): void
    {
        self::assertSame([], self::evaluate('array', []));
        self::assertSame([], self::evaluate('array', ['a']));
        self::assertNotEmpty(self::evaluate('array', new stdClass()));

        self::assertSame([], self::evaluate('object', new stdClass()));
        self::assertNotEmpty(self::evaluate('object', []));

        self::assertSame([], self::evaluate('iterable', []));
        self::assertSame([], self::evaluate('iterable', new ArrayIterator([1, 2])));
        self::assertSame([], self::evaluate('iterable', new ArrayObject([1, 2])));
        self::assertNotEmpty(self::evaluate('iterable', 'hello'));
    }

    public function test_enum_case_valid_and_invalid(): void
    {
        $errors = [];
        self::host()->enumCase($errors, 'f', SampleEnum::Foo, SampleEnum::class);
        self::assertSame([], $errors);

        $errors = [];
        self::host()->enumCase($errors, 'f', 'foo', SampleEnum::class);
        self::assertSame(['f' => ['This value should be a valid enum case.']], $errors);
    }

    public function test_enum_case_throws_on_non_enum_class(): void
    {
        $errors = [];
        $this->expectException(InvalidArgumentException::class);
        self::host()->enumCase($errors, 'f', 'x', stdClass::class);
    }

    public function test_backed_enum_value_valid_and_invalid(): void
    {
        $errors = [];
        self::host()->backedEnumValue($errors, 'f', 'foo', SampleEnum::class);
        self::assertSame([], $errors);

        $errors = [];
        self::host()->backedEnumValue($errors, 'f', 'nope', SampleEnum::class);
        self::assertSame(['f' => ['This value should be a valid enum value.']], $errors);

        $errors = [];
        self::host()->backedEnumValue($errors, 'f', 1.5, SampleEnum::class);
        self::assertSame(['f' => ['This value should be a valid enum value.']], $errors);
    }

    public function test_backed_enum_value_throws_on_pure_enum(): void
    {
        $errors = [];
        $this->expectException(InvalidArgumentException::class);
        self::host()->backedEnumValue($errors, 'f', 'a', PureEnum::class);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function evaluate(string $method, mixed $value): array
    {
        $errors = [];
        $h = self::host();
        match ($method) {
            'string'   => $h->string($errors, 'f', $value),
            'integer'  => $h->integer($errors, 'f', $value),
            'float'    => $h->float($errors, 'f', $value),
            'number'   => $h->number($errors, 'f', $value),
            'boolean'  => $h->boolean($errors, 'f', $value),
            'array'    => $h->arr($errors, 'f', $value),
            'object'   => $h->object($errors, 'f', $value),
            'iterable' => $h->iter($errors, 'f', $value),
        };
        return $errors;
    }

    private static function host(): object
    {
        return new class () {
            use TypeValidationTrait;
            /** @param array<string, list<string>> $errors */
            public function string(array &$errors, string $f, mixed $v): void  { $this->validateString($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function integer(array &$errors, string $f, mixed $v): void { $this->validateInteger($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function float(array &$errors, string $f, mixed $v): void   { $this->validateFloat($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function number(array &$errors, string $f, mixed $v): void  { $this->validateNumber($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function boolean(array &$errors, string $f, mixed $v): void { $this->validateBoolean($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function arr(array &$errors, string $f, mixed $v): void     { $this->validateArray($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function object(array &$errors, string $f, mixed $v): void  { $this->validateObject($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function iter(array &$errors, string $f, mixed $v): void    { $this->validateIterable($errors, $f, $v); }
            /**
             * @param array<string, list<string>> $errors
             * @param class-string                $enumClass
             */
            public function enumCase(array &$errors, string $f, mixed $v, string $enumClass): void
            { $this->validateEnumCase($errors, $f, $v, $enumClass); }
            /**
             * @param array<string, list<string>> $errors
             * @param class-string                $enumClass
             */
            public function backedEnumValue(array &$errors, string $f, mixed $v, string $enumClass): void
            { $this->validateBackedEnumValue($errors, $f, $v, $enumClass); }
        };
    }
}

enum SampleEnum: string
{
    case Foo = 'foo';
    case Bar = 'bar';
}

enum PureEnum
{
    case A;
    case B;
}
