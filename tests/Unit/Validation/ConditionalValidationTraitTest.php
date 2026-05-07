<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Validation\Trait\ConditionalValidationTrait;

final class ConditionalValidationTraitTest extends TestCase
{
    public function test_required_if_true_rejects_blank(): void
    {
        $errors = [];
        $h = self::host();
        $h->requiredIf($errors, 'a', null, true);
        $h->requiredIf($errors, 'b', '', true);
        $h->requiredIf($errors, 'c', '   ', true);
        $h->requiredIf($errors, 'd', [], true);
        self::assertSame(
            [
                'a' => ['This value is required.'],
                'b' => ['This value is required.'],
                'c' => ['This value is required.'],
                'd' => ['This value is required.'],
            ],
            $errors,
        );
    }

    public function test_required_if_false_accepts_blank(): void
    {
        $errors = [];
        self::host()->requiredIf($errors, 'a', null, false);
        self::assertSame([], $errors);
    }

    public function test_required_if_true_accepts_present_value(): void
    {
        $errors = [];
        self::host()->requiredIf($errors, 'a', 'hello', true);
        self::assertSame([], $errors);
    }

    public function test_prohibited_if_true_rejects_present_value(): void
    {
        $errors = [];
        $h = self::host();
        $h->prohibitedIf($errors, 'a', 'hello', true);
        self::assertSame(['a' => ['This value is not allowed in this context.']], $errors);

        $errors = [];
        $h->prohibitedIf($errors, 'a', null, true);
        self::assertSame([], $errors);
    }

    public function test_required_with(): void
    {
        $errors = [];
        $h = self::host();
        $h->requiredWith($errors, 'a', null, ['x']);
        self::assertSame(['a' => ['This value is required.']], $errors);

        $errors = [];
        $h->requiredWith($errors, 'a', null, [null, '', '   ']);
        self::assertSame([], $errors);

        $errors = [];
        $h->requiredWith($errors, 'a', 'present', ['x']);
        self::assertSame([], $errors);
    }

    public function test_required_without(): void
    {
        $errors = [];
        $h = self::host();
        $h->requiredWithout($errors, 'a', null, ['', null]);
        self::assertSame(['a' => ['This value is required.']], $errors);

        $errors = [];
        $h->requiredWithout($errors, 'a', null, ['present-1', 'present-2']);
        self::assertSame([], $errors);

        $errors = [];
        $h->requiredWithout($errors, 'a', 'now-present', ['']);
        self::assertSame([], $errors);
    }

    public function test_validate_if_runs_callback_only_when_true(): void
    {
        $errors = [];
        $cb = function (array &$e, string $f, mixed $v): void {
            $e[$f] = $e[$f] ?? [];
            $e[$f][] = 'inner-error';
        };

        self::host()->applyIf($errors, 'a', 'x', false, $cb);
        self::assertSame([], $errors);

        self::host()->applyIf($errors, 'b', 'x', true, $cb);
        self::assertSame(['b' => ['inner-error']], $errors);
    }

    public function test_validate_sometimes_skips_blank_runs_for_present(): void
    {
        $errors = [];
        $cb = function (array &$e, string $f, mixed $v): void {
            $e[$f] = $e[$f] ?? [];
            $e[$f][] = 'inner-error';
        };

        $h = self::host();
        $h->applySometimes($errors, 'a', null, $cb);
        $h->applySometimes($errors, 'b', '', $cb);
        $h->applySometimes($errors, 'c', '   ', $cb);
        $h->applySometimes($errors, 'd', [], $cb);
        self::assertSame([], $errors);

        $h->applySometimes($errors, 'e', 'present', $cb);
        $h->applySometimes($errors, 'f', 0, $cb);
        $h->applySometimes($errors, 'g', false, $cb);
        self::assertSame(
            [
                'e' => ['inner-error'],
                'f' => ['inner-error'],
                'g' => ['inner-error'],
            ],
            $errors,
        );
    }

    public function test_callback_errors_propagate(): void
    {
        $errors = [];
        $cb = function (array &$e, string $f, mixed $v): void {
            $e[$f] = $e[$f] ?? [];
            $e[$f][] = "rule-failure-on-{$v}";
        };

        self::host()->applyIf($errors, 'a', 'value', true, $cb);
        self::assertSame(['a' => ['rule-failure-on-value']], $errors);
    }

    private static function host(): object
    {
        return new class () {
            use ConditionalValidationTrait { validateIf as private traitValidateIf; validateSometimes as private traitValidateSometimes; }
            /** @param array<string, list<string>> $errors */
            public function requiredIf(array &$errors, string $f, mixed $v, bool $c): void
            { $this->validateRequiredIf($errors, $f, $v, $c); }
            /** @param array<string, list<string>> $errors */
            public function prohibitedIf(array &$errors, string $f, mixed $v, bool $c): void
            { $this->validateProhibitedIf($errors, $f, $v, $c); }
            /** @param array<string, list<string>> $errors */
            public function requiredWith(array &$errors, string $f, mixed $v, array $others): void
            { $this->validateRequiredWith($errors, $f, $v, $others); }
            /** @param array<string, list<string>> $errors */
            public function requiredWithout(array &$errors, string $f, mixed $v, array $others): void
            { $this->validateRequiredWithout($errors, $f, $v, $others); }
            /** @param array<string, list<string>> $errors */
            public function applyIf(array &$errors, string $f, mixed $v, bool $c, callable $cb): void
            { $this->traitValidateIf($errors, $f, $v, $c, $cb); }
            /** @param array<string, list<string>> $errors */
            public function applySometimes(array &$errors, string $f, mixed $v, callable $cb): void
            { $this->traitValidateSometimes($errors, $f, $v, $cb); }
        };
    }
}
