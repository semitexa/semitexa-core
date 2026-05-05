<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Validation;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Validation\Trait\StringValidationTrait;

final class StringValidationTraitTest extends TestCase
{
    public function test_length_min_max_inclusive(): void
    {
        $h = self::host();

        $errors = [];
        $h->length($errors, 'f', 'abc', 3, 5);
        $h->length($errors, 'f', 'abcde', 3, 5);
        self::assertSame([], $errors);

        $errors = [];
        $h->length($errors, 'f', 'ab', 3, 5);
        self::assertSame(['f' => ['This value should be at least 3 characters.']], $errors);

        $errors = [];
        $h->length($errors, 'f', 'abcdef', 3, 5);
        self::assertSame(['f' => ['This value should be at most 5 characters.']], $errors);
    }

    public function test_length_unicode_characters_counted_correctly(): void
    {
        $errors = [];
        self::host()->length($errors, 'f', 'café', 4, 4);
        self::assertSame([], $errors);
    }

    public function test_min_max_exact_length(): void
    {
        $h = self::host();

        $errors = [];
        $h->minLength($errors, 'f', 'ab', 3);
        self::assertNotEmpty($errors);

        $errors = [];
        $h->maxLength($errors, 'f', 'abcdef', 5);
        self::assertNotEmpty($errors);

        $errors = [];
        $h->exactLength($errors, 'f', 'abc', 3);
        self::assertSame([], $errors);

        $errors = [];
        $h->exactLength($errors, 'f', 'abcd', 3);
        self::assertSame(['f' => ['This value should be exactly 3 characters.']], $errors);
    }

    public function test_regex_match_and_mismatch(): void
    {
        $h = self::host();

        $errors = [];
        $h->regex($errors, 'f', 'abc-123', '/^[a-z\-0-9]+$/');
        self::assertSame([], $errors);

        $errors = [];
        $h->regex($errors, 'f', 'ABC', '/^[a-z]+$/');
        self::assertSame(['f' => ['This value is not in the expected format.']], $errors);
    }

    public function test_regex_invalid_pattern_throws_developer_exception(): void
    {
        $errors = [];
        $this->expectException(InvalidArgumentException::class);
        self::host()->regex($errors, 'f', 'x', '/[unterminated');
    }

    public function test_alpha_and_alphanumeric_ascii_only(): void
    {
        $h = self::host();

        $errors = [];
        $h->alpha($errors, 'f', 'Hello');
        self::assertSame([], $errors);

        $errors = [];
        $h->alpha($errors, 'f', 'café');
        self::assertNotEmpty($errors);

        $errors = [];
        $h->alpha($errors, 'f', 'abc123');
        self::assertSame(['f' => ['This value should contain only letters.']], $errors);

        $errors = [];
        $h->alphaNumeric($errors, 'f', 'abc123');
        self::assertSame([], $errors);

        $errors = [];
        $h->alphaNumeric($errors, 'f', 'abc-123');
        self::assertNotEmpty($errors);
    }

    public function test_starts_ends_contains(): void
    {
        $h = self::host();

        $errors = [];
        $h->startsWith($errors, 'f', 'sk_live_abc', 'sk_');
        self::assertSame([], $errors);

        $errors = [];
        $h->startsWith($errors, 'f', 'pk_live_abc', 'sk_');
        self::assertNotEmpty($errors);

        $errors = [];
        $h->endsWith($errors, 'f', 'photo.png', '.png');
        self::assertSame([], $errors);

        $errors = [];
        $h->endsWith($errors, 'f', 'photo.jpg', '.png');
        self::assertNotEmpty($errors);

        $errors = [];
        $h->contains($errors, 'f', 'a@b.com', '@');
        self::assertSame([], $errors);

        $errors = [];
        $h->contains($errors, 'f', 'abc', '@');
        self::assertNotEmpty($errors);

        $errors = [];
        $h->notContains($errors, 'f', 'abc', '@');
        self::assertSame([], $errors);

        $errors = [];
        $h->notContains($errors, 'f', 'a@b', '@');
        self::assertNotEmpty($errors);
    }

    public function test_lower_and_uppercase(): void
    {
        $h = self::host();

        $errors = [];
        $h->lowercase($errors, 'f', 'hello');
        self::assertSame([], $errors);

        $errors = [];
        $h->lowercase($errors, 'f', 'Hello');
        self::assertNotEmpty($errors);

        $errors = [];
        $h->uppercase($errors, 'f', 'HELLO');
        self::assertSame([], $errors);

        $errors = [];
        $h->uppercase($errors, 'f', 'Hello');
        self::assertNotEmpty($errors);
    }

    public function test_null_is_silently_accepted_by_every_method(): void
    {
        $h = self::host();
        $errors = [];

        $h->length($errors, 'a', null, 1, 9);
        $h->minLength($errors, 'b', null, 1);
        $h->maxLength($errors, 'c', null, 9);
        $h->exactLength($errors, 'd', null, 9);
        $h->regex($errors, 'e', null, '/.*/');
        $h->alpha($errors, 'f', null);
        $h->alphaNumeric($errors, 'g', null);
        $h->startsWith($errors, 'h', null, 'x');
        $h->endsWith($errors, 'i', null, 'x');
        $h->contains($errors, 'j', null, 'x');
        $h->notContains($errors, 'k', null, 'x');
        $h->lowercase($errors, 'l', null);
        $h->uppercase($errors, 'm', null);

        self::assertSame([], $errors);
    }

    private static function host(): object
    {
        return new class () {
            use StringValidationTrait;
            /** @param array<string, list<string>> $errors */
            public function length(array &$errors, string $f, ?string $v, ?int $min = null, ?int $max = null): void
            { $this->validateLength($errors, $f, $v, $min, $max); }
            /** @param array<string, list<string>> $errors */
            public function minLength(array &$errors, string $f, ?string $v, int $min): void
            { $this->validateMinLength($errors, $f, $v, $min); }
            /** @param array<string, list<string>> $errors */
            public function maxLength(array &$errors, string $f, ?string $v, int $max): void
            { $this->validateMaxLength($errors, $f, $v, $max); }
            /** @param array<string, list<string>> $errors */
            public function exactLength(array &$errors, string $f, ?string $v, int $len): void
            { $this->validateExactLength($errors, $f, $v, $len); }
            /** @param array<string, list<string>> $errors */
            public function regex(array &$errors, string $f, ?string $v, string $p): void
            { $this->validateRegex($errors, $f, $v, $p); }
            /** @param array<string, list<string>> $errors */
            public function alpha(array &$errors, string $f, ?string $v): void
            { $this->validateAlpha($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function alphaNumeric(array &$errors, string $f, ?string $v): void
            { $this->validateAlphaNumeric($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function startsWith(array &$errors, string $f, ?string $v, string $p): void
            { $this->validateStartsWith($errors, $f, $v, $p); }
            /** @param array<string, list<string>> $errors */
            public function endsWith(array &$errors, string $f, ?string $v, string $p): void
            { $this->validateEndsWith($errors, $f, $v, $p); }
            /** @param array<string, list<string>> $errors */
            public function contains(array &$errors, string $f, ?string $v, string $p): void
            { $this->validateContains($errors, $f, $v, $p); }
            /** @param array<string, list<string>> $errors */
            public function notContains(array &$errors, string $f, ?string $v, string $p): void
            { $this->validateNotContains($errors, $f, $v, $p); }
            /** @param array<string, list<string>> $errors */
            public function lowercase(array &$errors, string $f, ?string $v): void
            { $this->validateLowercase($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function uppercase(array &$errors, string $f, ?string $v): void
            { $this->validateUppercase($errors, $f, $v); }
        };
    }
}
