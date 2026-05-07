<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Validation;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Validation\Path;

/**
 * Contract test for the nested validation-path key builder. The framework's
 * validation-error envelope keeps its flat `array<string, list<string>>`
 * shape; nested support comes from this helper composing keys like
 * `items[0].sku` and `address.country`.
 */
final class PathTest extends TestCase
{
    /**
     * @return iterable<string, array{0: list<int|string>, 1: string}>
     */
    public static function compositions(): iterable
    {
        yield 'simple field'        => [['email'], 'email'];
        yield 'nested object path'  => [['address', 'country'], 'address.country'];
        yield 'array index path'    => [['items', 0], 'items[0]'];
        yield 'array indexed field' => [['items', 0, 'sku'], 'items[0].sku'];
        yield 'mixed nested path'   => [['metadata', 'tags', 2], 'metadata.tags[2]'];
        yield 'nested in nested'    => [['items', 3, 'tags', 1], 'items[3].tags[1]'];
    }

    /**
     * @dataProvider compositions
     * @param list<int|string> $segments
     */
    public function test_to_string_for_segments(array $segments, string $expected): void
    {
        self::assertSame($expected, Path::of(...$segments)->toString());
    }

    /**
     * @dataProvider compositions
     * @param list<int|string> $segments
     */
    public function test_php_to_string_matches_to_string(array $segments, string $expected): void
    {
        self::assertSame($expected, (string) Path::of(...$segments));
    }

    public function test_join_returns_string_directly(): void
    {
        self::assertSame('items[0].sku', Path::join('items', 0, 'sku'));
    }

    public function test_segments_are_preserved(): void
    {
        $path = Path::of('items', 0, 'sku');

        self::assertSame(['items', 0, 'sku'], $path->segments());
    }

    public function test_append_returns_new_immutable_instance(): void
    {
        $base   = Path::of('items', 0);
        $longer = $base->append('sku');

        self::assertSame('items[0]', $base->toString());
        self::assertSame('items[0].sku', $longer->toString());
        self::assertNotSame($base, $longer);
    }

    /**
     * @return iterable<string, array{0: list<int|string>}>
     */
    public static function rejectedSegments(): iterable
    {
        yield 'empty string'       => [['']];
        yield 'whitespace only'    => [['   ']];
        yield 'empty inside chain' => [['items', '', 'sku']];
    }

    /**
     * @dataProvider rejectedSegments
     * @param list<int|string> $segments
     */
    public function test_empty_string_segment_is_rejected(array $segments): void
    {
        $this->expectException(InvalidArgumentException::class);

        Path::of(...$segments);
    }

    public function test_zero_segment_count_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Path::of();
    }

    public function test_zero_index_renders_with_brackets(): void
    {
        self::assertSame('items[0]', Path::of('items', 0)->toString());
    }
}
