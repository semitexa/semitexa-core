<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Validation\Trait\ComparisonValidationTrait;

final class ComparisonValidationTraitTest extends TestCase
{
    public function test_equal_to_strict_and_loose(): void
    {
        $errors = [];
        $h = self::host();
        $h->equalTo($errors, 'a', 1, 1);
        self::assertSame([], $errors);

        $h->equalTo($errors, 'b', '1', 1, strict: true);
        self::assertNotEmpty($errors['b']);

        $errors = [];
        $h->equalTo($errors, 'a', '1', 1, strict: false);
        self::assertSame([], $errors);
    }

    public function test_equal_to_treats_null_as_meaningful(): void
    {
        $errors = [];
        $h = self::host();
        $h->equalTo($errors, 'a', null, null);
        self::assertSame([], $errors);

        $h->equalTo($errors, 'b', null, 0);
        self::assertNotEmpty($errors['b']);
    }

    public function test_not_equal_to(): void
    {
        $errors = [];
        $h = self::host();
        $h->notEqualTo($errors, 'a', 'x', 'y');
        self::assertSame([], $errors);

        $h->notEqualTo($errors, 'b', 'x', 'x');
        self::assertSame(['b' => ['This value should not equal the disallowed value.']], $errors);
    }

    public function test_identical_to(): void
    {
        $errors = [];
        $h = self::host();
        $h->identicalTo($errors, 'a', 1, 1);
        self::assertSame([], $errors);

        $h->identicalTo($errors, 'b', '1', 1);
        self::assertNotEmpty($errors['b']);
    }

    public function test_not_identical_to(): void
    {
        $errors = [];
        $h = self::host();
        $h->notIdenticalTo($errors, 'a', 1, '1');
        self::assertSame([], $errors);

        $h->notIdenticalTo($errors, 'b', 1, 1);
        self::assertNotEmpty($errors['b']);
    }

    public function test_same_as_field_message_mentions_other_field_only(): void
    {
        $errors = [];
        self::host()->sameAsField(
            $errors,
            'password_confirmation',
            'wrong-secret-DO-NOT-LEAK',
            otherField: 'password',
            otherValue: 'expected-secret-DO-NOT-LEAK',
        );

        self::assertCount(1, $errors['password_confirmation']);
        self::assertSame('This value should match password.', $errors['password_confirmation'][0]);
        self::assertStringNotContainsString('wrong-secret', $errors['password_confirmation'][0]);
        self::assertStringNotContainsString('expected-secret', $errors['password_confirmation'][0]);
    }

    public function test_same_as_field_passes_when_equal(): void
    {
        $errors = [];
        self::host()->sameAsField($errors, 'b', 's3cret', otherField: 'a', otherValue: 's3cret');
        self::assertSame([], $errors);
    }

    public function test_different_from_field_message_mentions_other_field_only(): void
    {
        $errors = [];
        self::host()->differentFromField(
            $errors,
            'new_password',
            'sekret-DO-NOT-LEAK',
            otherField: 'old_password',
            otherValue: 'sekret-DO-NOT-LEAK',
        );

        self::assertCount(1, $errors['new_password']);
        self::assertSame('This value should differ from old_password.', $errors['new_password'][0]);
        self::assertStringNotContainsString('sekret', $errors['new_password'][0]);
    }

    public function test_different_from_field_passes_when_different(): void
    {
        $errors = [];
        self::host()->differentFromField($errors, 'b', 'new', otherField: 'a', otherValue: 'old');
        self::assertSame([], $errors);
    }

    private static function host(): object
    {
        return new class () {
            use ComparisonValidationTrait;
            /** @param array<string, list<string>> $errors */
            public function equalTo(array &$errors, string $f, mixed $v, mixed $e, bool $strict = true): void
            { $this->validateEqualTo($errors, $f, $v, $e, $strict); }
            /** @param array<string, list<string>> $errors */
            public function notEqualTo(array &$errors, string $f, mixed $v, mixed $e, bool $strict = true): void
            { $this->validateNotEqualTo($errors, $f, $v, $e, $strict); }
            /** @param array<string, list<string>> $errors */
            public function identicalTo(array &$errors, string $f, mixed $v, mixed $e): void
            { $this->validateIdenticalTo($errors, $f, $v, $e); }
            /** @param array<string, list<string>> $errors */
            public function notIdenticalTo(array &$errors, string $f, mixed $v, mixed $e): void
            { $this->validateNotIdenticalTo($errors, $f, $v, $e); }
            /** @param array<string, list<string>> $errors */
            public function sameAsField(array &$errors, string $f, mixed $v, string $otherField, mixed $otherValue, bool $strict = true): void
            { $this->validateSameAsField($errors, $f, $v, $otherField, $otherValue, $strict); }
            /** @param array<string, list<string>> $errors */
            public function differentFromField(array &$errors, string $f, mixed $v, string $otherField, mixed $otherValue, bool $strict = true): void
            { $this->validateDifferentFromField($errors, $f, $v, $otherField, $otherValue, $strict); }
        };
    }
}
