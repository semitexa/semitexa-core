<?php

declare(strict_types=1);

namespace Semitexa\Core\Support;

/**
 * One loosely-typed row, read as the type the caller actually needs.
 *
 * A `Swoole\Table` row, a `debug_backtrace()` frame and a decoded JSON object
 * all arrive as `array<mixed, mixed>`: the keys are known to the programmer and
 * to nothing else. The habit that grew around that was `(string) ($row['col']
 * ?? '')` at the point of use, which is wrong twice over — the analyser cannot
 * see a string there (eighty-three "Cannot cast mixed to string" in one
 * measurement, the single largest cluster in the project), and casting an ARRAY
 * value that way is a PHP error rather than a default, so a malformed row took
 * the worker down instead of being skipped.
 *
 * So the narrowing happens ONCE, here, where it can be read and tested, and a
 * value of the wrong shape yields the default instead of a fatal.
 *
 * Deliberately small. This is not a validator and not a DTO: it answers "what
 * string is at this key" and nothing more. When a row has a fixed shape that
 * outlives the read, give it a readonly class instead — see
 * {@see \Semitexa\Ssr\Application\Service\Async\SubscriptionRecord} for the
 * pattern this is the raw-input half of.
 */
final readonly class Row
{
    /** @param array<mixed, mixed> $values */
    private function __construct(private array $values)
    {
    }

    /** @param array<mixed, mixed> $values */
    public static function of(array $values): self
    {
        return new self($values);
    }

    /**
     * The same array, with its keys known to be strings.
     *
     * A JSON object and a JSON ARRAY both decode to `array` — the second with
     * integer keys — so `json_decode(..., true)` is `array<mixed>` no matter
     * what the sender meant. Every consumer downstream declares
     * `array<string, mixed>`. One named conversion, rather than the question
     * being reopened at each boundary.
     *
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    public static function keyedByName(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * Whether the key is present at all.
     *
     * Distinct from a default: an absent column and a column holding `''` mean
     * different things to a reaper deciding whether a row was ever written.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * The value at `$key` as a string, or `$default`.
     *
     * An array, an object without __toString, or null yields the default — the
     * three cases where the inline cast either fatals or produces nonsense.
     */
    public function string(string $key, string $default = ''): string
    {
        return self::asString($this->values[$key] ?? null, $default);
    }

    /**
     * The value at `$key` as an int, or `$default`.
     *
     * A NUMERIC string counts: a Swoole\Table column declared as string and a
     * timestamp written into it is the common case, and refusing it here would
     * only push the cast back out to the call site.
     */
    public function int(string $key, int $default = 0): int
    {
        return self::asInt($this->values[$key] ?? null, $default);
    }

    /**
     * One loose value as a string, for the cases with no row around it: a
     * reflective `$object->getId()`, a dynamic property, a decoded scalar.
     *
     * An array, an object without __toString, or null yields the default — the
     * three cases where the inline cast either fatals or produces nonsense.
     */
    public static function asString(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return $default;
    }

    /**
     * The value at `$key` as a float, or `$default`.
     *
     * SQL aggregates arrive this way: a driver returns SUM and AVG as strings,
     * and NULL when nothing matched, so the cast at the call site had to guess
     * which it was holding.
     */
    public function float(string $key, float $default = 0.0): float
    {
        return self::asFloat($this->values[$key] ?? null, $default);
    }

    /** One loose value as a float. */
    public static function asFloat(mixed $value, float $default = 0.0): float
    {
        if (is_float($value)) {
            return $value;
        }
        if (is_int($value) || is_bool($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return $default;
    }

    /**
     * One loose value as an int.
     *
     * A NUMERIC string counts: a Swoole\Table column declared as string and a
     * timestamp written into it is the common case, and refusing it here would
     * only push the cast back out to the call site.
     */
    public static function asInt(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return (int) $value;
        }
        if (is_float($value)) {
            return is_finite($value) ? (int) $value : $default;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }
}
