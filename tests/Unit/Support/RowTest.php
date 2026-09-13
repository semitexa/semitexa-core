<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\Row;

/**
 * The narrowing that used to sit inline at eighty-three call sites.
 *
 * Two things are under test and only one of them is about types: a row whose
 * column holds something unexpected must yield the DEFAULT, because the inline
 * `(string) $row['col']` it replaces raised "Array to string conversion" and
 * took the worker with it.
 */
final class RowTest extends TestCase
{
    #[Test]
    public function a_string_column_reads_back_as_itself(): void
    {
        self::assertSame('abc', Row::of(['a' => 'abc'])->string('a'));
    }

    #[Test]
    public function a_scalar_column_is_spelled_as_a_string(): void
    {
        self::assertSame('7', Row::of(['a' => 7])->string('a'));
        self::assertSame('1', Row::of(['a' => true])->string('a'));
        self::assertSame('1.5', Row::of(['a' => 1.5])->string('a'));
    }

    #[Test]
    public function a_stringable_object_is_allowed_to_spell_itself(): void
    {
        $subject = new class () implements \Stringable {
            public function __toString(): string
            {
                return 'from an object';
            }
        };

        self::assertSame('from an object', Row::of(['a' => $subject])->string('a'));
    }

    /**
     * The behavioural half. `(string) ['x']` is a PHP error, not a cast, so a
     * malformed row killed the read instead of being skipped.
     */
    #[Test]
    public function a_value_that_cannot_be_a_string_yields_the_default(): void
    {
        self::assertSame('', Row::of(['a' => ['x']])->string('a'));
        self::assertSame('', Row::of(['a' => null])->string('a'));
        self::assertSame('fallback', Row::of(['a' => new \stdClass()])->string('a', 'fallback'));
        self::assertSame('fallback', Row::of([])->string('a', 'fallback'));
    }

    #[Test]
    public function an_int_column_reads_back_as_itself(): void
    {
        self::assertSame(7, Row::of(['a' => 7])->int('a'));
        self::assertSame(1, Row::of(['a' => true])->int('a'));
    }

    /**
     * A Swoole\Table column declared as a string, holding a timestamp, is the
     * common case; refusing it would only push the cast back to the call site.
     */
    #[Test]
    public function a_numeric_string_is_an_int(): void
    {
        self::assertSame(1700000000, Row::of(['a' => '1700000000'])->int('a'));
        self::assertSame(-3, Row::of(['a' => '-3'])->int('a'));
    }

    #[Test]
    public function a_value_that_is_not_a_number_yields_the_default(): void
    {
        self::assertSame(0, Row::of(['a' => 'later'])->int('a'));
        self::assertSame(0, Row::of(['a' => ['x']])->int('a'));
        self::assertSame(0, Row::of(['a' => null])->int('a'));
        self::assertSame(-1, Row::of([])->int('a', -1));
        self::assertSame(-1, Row::of(['a' => NAN])->int('a', -1), 'NAN has no integer to be');
        self::assertSame(-1, Row::of(['a' => INF])->int('a', -1));
    }

    /**
     * Absence and emptiness are different questions: a reaper deciding whether
     * a row was ever written needs the first one.
     */
    #[Test]
    public function presence_is_asked_separately_from_value(): void
    {
        self::assertTrue(Row::of(['a' => ''])->has('a'));
        self::assertTrue(Row::of(['a' => null])->has('a'), 'written, and written as null');
        self::assertFalse(Row::of([])->has('a'));
    }

    /** Numeric-looking keys arrive from a table the same as any other. */
    #[Test]
    public function a_row_with_unknown_key_types_is_read_the_same_way(): void
    {
        self::assertSame('v', Row::of([0 => 'skip', 'a' => 'v'])->string('a'));
    }

    /**
     * A list has no named entries, so it contributes none.
     *
     * The earlier version cast each key with `(string) $key` and claimed to
     * produce string keys. PHP converts a canonical numeric string key straight
     * back to an integer, so it did not — and the test could not tell, because
     * `['0' => 'a']` and `[0 => 'a']` ARE the same array and assertSame was
     * comparing a value with itself. Raised in review of core#137.
     */
    #[Test]
    public function a_list_contributes_no_named_entries(): void
    {
        self::assertSame([], Row::keyedByName(['a', 'b']));
    }

    /** The property the old version claimed and could not deliver. */
    #[Test]
    public function every_surviving_key_is_really_a_string(): void
    {
        $out = Row::keyedByName(['a', 'name' => 'Ada', 7 => 'seven', 'id' => 3]);

        foreach (array_keys($out) as $key) {
            self::assertIsString($key, 'an int key here is what the cast used to produce');
        }
        self::assertSame(['name' => 'Ada', 'id' => 3], $out);
    }

    #[Test]
    public function an_already_named_map_is_unchanged(): void
    {
        self::assertSame(['a' => 1, 'b' => 2], Row::keyedByName(['a' => 1, 'b' => 2]));
    }

    #[Test]
    public function values_are_carried_through_untouched(): void
    {
        $nested = ['deep' => ['x']];

        self::assertSame(['k' => $nested], Row::keyedByName(['k' => $nested]), 'this narrows KEYS, not values');
        self::assertSame([], Row::keyedByName([]));
    }
}
