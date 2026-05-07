<?php

declare(strict_types=1);

namespace Semitexa\Core\Validation;

use InvalidArgumentException;
use Stringable;

/**
 * Stable nested validation key.
 *
 * Builds dotted/bracketed strings used as keys inside the
 * `array<string, list<string>>` validation-error envelope so collection
 * and nested-payload errors carry a precise location:
 *
 *     Path::of('email')->toString()                 // 'email'
 *     Path::of('address', 'country')->toString()    // 'address.country'
 *     Path::of('items', 0, 'sku')->toString()       // 'items[0].sku'
 *     Path::of('metadata', 'tags', 2)->toString()   // 'metadata.tags[2]'
 *
 * String segments are joined with `.`; integer segments are emitted as
 * `[n]` and never produce a leading `.`. Empty / whitespace-only string
 * segments are rejected because they would produce an ambiguous path
 * (`a..b`, `.a`).
 *
 * The envelope itself is unchanged — keys are still strings — so adding
 * paths is purely a key-naming convention.
 */
final class Path implements Stringable
{
    /**
     * @var list<int|string>
     */
    private readonly array $segments;

    private function __construct(int|string ...$segments)
    {
        foreach ($segments as $index => $segment) {
            if (is_string($segment) && trim($segment) === '') {
                throw new InvalidArgumentException(
                    "Path segment at index {$index} must be a non-empty string or an integer.",
                );
            }
        }

        $this->segments = array_values($segments);
    }

    public static function of(int|string ...$segments): self
    {
        if ($segments === []) {
            throw new InvalidArgumentException('Path requires at least one segment.');
        }

        return new self(...$segments);
    }

    /**
     * Convenience: build a path key directly without retaining the value object.
     */
    public static function join(int|string ...$segments): string
    {
        return self::of(...$segments)->toString();
    }

    public function toString(): string
    {
        $out = '';
        foreach ($this->segments as $segment) {
            if (is_int($segment)) {
                $out .= "[{$segment}]";
                continue;
            }

            $out .= ($out === '') ? $segment : ".{$segment}";
        }

        return $out;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * @return list<int|string>
     */
    public function segments(): array
    {
        return $this->segments;
    }

    public function append(int|string ...$segments): self
    {
        return new self(...[...$this->segments, ...$segments]);
    }
}
