<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Validation\Trait\PresenceValidationTrait;

final class PresenceValidationTraitTest extends TestCase
{
    public function test_required_rejects_null(): void
    {
        $errors = [];
        self::host()->required($errors, 'email', null);

        self::assertSame(['email' => ['This value is required.']], $errors);
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function requiredAccepts(): iterable
    {
        yield 'empty string' => [''];
        yield 'whitespace'   => ['   '];
        yield 'empty array'  => [[]];
        yield 'zero int'     => [0];
        yield 'false bool'   => [false];
        yield 'value'        => ['hi'];
    }

    /**
     * @dataProvider requiredAccepts
     */
    public function test_required_accepts_any_non_null_value(mixed $value): void
    {
        $errors = [];
        self::host()->required($errors, 'field', $value);

        self::assertSame([], $errors);
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function notBlankRejects(): iterable
    {
        yield 'null'             => [null];
        yield 'empty string'     => [''];
        yield 'whitespace string'=> ['  '];
        yield 'tab+newline'      => ["\t\n"];
        yield 'empty array'      => [[]];
    }

    /**
     * @dataProvider notBlankRejects
     */
    public function test_not_blank_rejects_blank_inputs(mixed $value): void
    {
        $errors = [];
        self::host()->notBlank($errors, 'field', $value);

        self::assertSame(['field' => ['This value should not be blank.']], $errors);
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function notBlankAccepts(): iterable
    {
        yield 'non-empty string'    => ['hello'];
        yield 'whitespace+content'  => ['  ok  '];
        yield 'zero string'         => ['0'];
        yield 'non-empty array'     => [['a']];
        yield 'zero int'            => [0];
        yield 'false bool'          => [false];
    }

    /**
     * @dataProvider notBlankAccepts
     */
    public function test_not_blank_accepts_non_blank_inputs(mixed $value): void
    {
        $errors = [];
        self::host()->notBlank($errors, 'field', $value);

        self::assertSame([], $errors);
    }

    public function test_blank_accepts_blank_inputs(): void
    {
        $errors = [];
        $h = self::host();
        $h->blank($errors, 'a', null);
        $h->blank($errors, 'b', '');
        $h->blank($errors, 'c', '   ');
        $h->blank($errors, 'd', []);

        self::assertSame([], $errors);
    }

    public function test_blank_rejects_non_blank_inputs(): void
    {
        $errors = [];
        self::host()->blank($errors, 'name', 'taras');

        self::assertSame(['name' => ['This value should be blank.']], $errors);
    }

    public function test_not_null_rejects_null_only(): void
    {
        $errors = [];
        $h = self::host();
        $h->notNull($errors, 'a', null);
        $h->notNull($errors, 'b', '');
        $h->notNull($errors, 'c', 0);

        self::assertSame(['a' => ['This value should not be null.']], $errors);
    }

    public function test_is_null_rejects_non_null(): void
    {
        $errors = [];
        $h = self::host();
        $h->isNull($errors, 'a', null);
        $h->isNull($errors, 'b', '');

        self::assertSame(['b' => ['This value should be null.']], $errors);
    }

    public function test_optional_returns_false_for_null(): void
    {
        $errors = [];
        self::assertFalse(self::host()->optional($errors, 'website', null));
        self::assertSame([], $errors);
    }

    public function test_optional_returns_true_for_non_null(): void
    {
        $errors = [];
        self::assertTrue(self::host()->optional($errors, 'website', ''));
        self::assertTrue(self::host()->optional($errors, 'website', 'https://example.com'));
        self::assertSame([], $errors);
    }

    private static function host(): object
    {
        return new class () {
            use PresenceValidationTrait;

            /**
             * @param array<string, list<string>> $errors
             */
            public function required(array &$errors, string $f, mixed $v): void  { $this->validateRequired($errors, $f, $v); }
            /**
             * @param array<string, list<string>> $errors
             */
            public function notBlank(array &$errors, string $f, mixed $v): void  { $this->validateNotBlank($errors, $f, $v); }
            /**
             * @param array<string, list<string>> $errors
             */
            public function blank(array &$errors, string $f, mixed $v): void     { $this->validateBlank($errors, $f, $v); }
            /**
             * @param array<string, list<string>> $errors
             */
            public function notNull(array &$errors, string $f, mixed $v): void   { $this->validateNotNull($errors, $f, $v); }
            /**
             * @param array<string, list<string>> $errors
             */
            public function isNull(array &$errors, string $f, mixed $v): void    { $this->validateIsNull($errors, $f, $v); }
            /**
             * @param array<string, list<string>> $errors
             */
            public function optional(array &$errors, string $f, mixed $v): bool  { return $this->validateOptional($errors, $f, $v); }
        };
    }
}
