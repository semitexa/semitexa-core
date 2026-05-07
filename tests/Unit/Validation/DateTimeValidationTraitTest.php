<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Validation;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Validation\Trait\DateTimeValidationTrait;

final class DateTimeValidationTraitTest extends TestCase
{
    public function test_date_strict_round_trip_accepts_valid_iso(): void
    {
        $errors = [];
        self::host()->date($errors, 'f', '2025-01-15');
        self::assertSame([], $errors);
    }

    public function test_date_strict_round_trip_rejects_overflow(): void
    {
        $errors = [];
        self::host()->date($errors, 'f', '2025-02-30');
        self::assertSame(['f' => ['This value should be a valid date.']], $errors);
    }

    public function test_date_rejects_garbage_and_partial_input(): void
    {
        $errors = [];
        $h = self::host();
        $h->date($errors, 'a', 'not-a-date');
        $h->date($errors, 'b', '2025-1-1');
        self::assertNotEmpty($errors['a']);
        self::assertNotEmpty($errors['b']);
    }

    public function test_datetime_default_atom_format(): void
    {
        $errors = [];
        $h = self::host();
        $h->datetime($errors, 'a', '2025-01-15T10:00:00+00:00');
        self::assertSame([], $errors);

        $errors = [];
        $h->datetime($errors, 'b', '2025-01-15 10:00:00');
        self::assertNotEmpty($errors);
    }

    public function test_time_round_trip(): void
    {
        $errors = [];
        $h = self::host();
        $h->time($errors, 'a', '14:30:00');
        self::assertSame([], $errors);

        $errors = [];
        $h->time($errors, 'b', '25:00:00');
        self::assertNotEmpty($errors);
    }

    public function test_before_with_datetime_threshold(): void
    {
        $errors = [];
        $h = self::host();
        $threshold = new DateTimeImmutable('2025-01-15T00:00:00+00:00');

        $h->before($errors, 'a', new DateTimeImmutable('2025-01-14T00:00:00+00:00'), $threshold);
        self::assertSame([], $errors);

        $h->before($errors, 'b', new DateTimeImmutable('2025-01-15T00:00:00+00:00'), $threshold);
        $h->before($errors, 'c', new DateTimeImmutable('2025-01-16T00:00:00+00:00'), $threshold);
        self::assertNotEmpty($errors['b']);
        self::assertNotEmpty($errors['c']);
    }

    public function test_before_or_equal_includes_boundary(): void
    {
        $errors = [];
        $h = self::host();
        $threshold = new DateTimeImmutable('2025-01-15T00:00:00+00:00');
        $h->beforeOrEqual($errors, 'a', new DateTimeImmutable('2025-01-15T00:00:00+00:00'), $threshold);
        self::assertSame([], $errors);
    }

    public function test_after_with_string_threshold_parses(): void
    {
        $errors = [];
        $h = self::host();
        $h->after($errors, 'a', '2025-01-16T00:00:00+00:00', '2025-01-15T00:00:00+00:00');
        self::assertSame([], $errors);

        $h->after($errors, 'b', '2025-01-14T00:00:00+00:00', '2025-01-15T00:00:00+00:00');
        self::assertNotEmpty($errors['b']);
    }

    public function test_after_or_equal_includes_boundary(): void
    {
        $errors = [];
        $h = self::host();
        $h->afterOrEqual($errors, 'a', '2025-01-15T00:00:00+00:00', '2025-01-15T00:00:00+00:00');
        self::assertSame([], $errors);
    }

    public function test_invalid_threshold_string_throws_developer_error(): void
    {
        $errors = [];
        $this->expectException(InvalidArgumentException::class);
        self::host()->before($errors, 'f', '2025-01-15T00:00:00+00:00', 'not-a-date');
    }

    public function test_invalid_user_value_string_records_validation_error(): void
    {
        $errors = [];
        self::host()->before($errors, 'f', 'not-a-date', '2025-01-15T00:00:00+00:00');
        self::assertSame(['f' => ['This value should be a valid datetime.']], $errors);
    }

    public function test_past_with_injected_now(): void
    {
        $errors = [];
        $h = self::host();
        $now = new DateTimeImmutable('2025-06-01T00:00:00+00:00');

        $h->past($errors, 'a', '2025-05-01T00:00:00+00:00', $now);
        self::assertSame([], $errors);

        $h->past($errors, 'b', '2025-07-01T00:00:00+00:00', $now);
        self::assertNotEmpty($errors['b']);
    }

    public function test_past_or_present_at_boundary(): void
    {
        $errors = [];
        $now = new DateTimeImmutable('2025-06-01T00:00:00+00:00');
        self::host()->pastOrPresent($errors, 'a', '2025-06-01T00:00:00+00:00', $now);
        self::assertSame([], $errors);
    }

    public function test_future_with_injected_now(): void
    {
        $errors = [];
        $h = self::host();
        $now = new DateTimeImmutable('2025-06-01T00:00:00+00:00');

        $h->future($errors, 'a', '2025-07-01T00:00:00+00:00', $now);
        self::assertSame([], $errors);

        $h->future($errors, 'b', '2025-05-01T00:00:00+00:00', $now);
        self::assertNotEmpty($errors['b']);
    }

    public function test_future_or_present_at_boundary(): void
    {
        $errors = [];
        $now = new DateTimeImmutable('2025-06-01T00:00:00+00:00');
        self::host()->futureOrPresent($errors, 'a', '2025-06-01T00:00:00+00:00', $now);
        self::assertSame([], $errors);
    }

    public function test_null_is_silently_accepted(): void
    {
        $errors = [];
        $h = self::host();
        $h->date($errors, 'a', null);
        $h->datetime($errors, 'b', null);
        $h->time($errors, 'c', null);
        $h->before($errors, 'd', null, '2025-01-01');
        $h->after($errors, 'e', null, '2025-01-01');
        $h->past($errors, 'f', null);
        $h->future($errors, 'g', null);
        self::assertSame([], $errors);
    }

    private static function host(): object
    {
        return new class () {
            use DateTimeValidationTrait;
            /** @param array<string, list<string>> $errors */
            public function date(array &$errors, string $f, ?string $v, string $fmt = 'Y-m-d'): void
            { $this->validateDate($errors, $f, $v, $fmt); }
            /** @param array<string, list<string>> $errors */
            public function datetime(array &$errors, string $f, ?string $v): void
            { $this->validateDateTime($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function time(array &$errors, string $f, ?string $v): void
            { $this->validateTime($errors, $f, $v); }
            /** @param array<string, list<string>> $errors */
            public function before(array &$errors, string $f, \DateTimeInterface|string|null $v, \DateTimeInterface|string $t): void
            { $this->validateBefore($errors, $f, $v, $t); }
            /** @param array<string, list<string>> $errors */
            public function beforeOrEqual(array &$errors, string $f, \DateTimeInterface|string|null $v, \DateTimeInterface|string $t): void
            { $this->validateBeforeOrEqual($errors, $f, $v, $t); }
            /** @param array<string, list<string>> $errors */
            public function after(array &$errors, string $f, \DateTimeInterface|string|null $v, \DateTimeInterface|string $t): void
            { $this->validateAfter($errors, $f, $v, $t); }
            /** @param array<string, list<string>> $errors */
            public function afterOrEqual(array &$errors, string $f, \DateTimeInterface|string|null $v, \DateTimeInterface|string $t): void
            { $this->validateAfterOrEqual($errors, $f, $v, $t); }
            /** @param array<string, list<string>> $errors */
            public function past(array &$errors, string $f, \DateTimeInterface|string|null $v, ?\DateTimeInterface $now = null): void
            { $this->validatePast($errors, $f, $v, $now); }
            /** @param array<string, list<string>> $errors */
            public function pastOrPresent(array &$errors, string $f, \DateTimeInterface|string|null $v, ?\DateTimeInterface $now = null): void
            { $this->validatePastOrPresent($errors, $f, $v, $now); }
            /** @param array<string, list<string>> $errors */
            public function future(array &$errors, string $f, \DateTimeInterface|string|null $v, ?\DateTimeInterface $now = null): void
            { $this->validateFuture($errors, $f, $v, $now); }
            /** @param array<string, list<string>> $errors */
            public function futureOrPresent(array &$errors, string $f, \DateTimeInterface|string|null $v, ?\DateTimeInterface $now = null): void
            { $this->validateFutureOrPresent($errors, $f, $v, $now); }
        };
    }
}
