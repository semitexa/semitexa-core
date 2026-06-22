<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Exception\InvalidPaginationException;
use Semitexa\Core\Resource\Pagination\CollectionPageRequest;

/**
 * One Way: per-route bounds on the page-request parser.
 * The defaults keep every pre-Phase-2 call site byte-identical;
 * declared bounds replace the static constants in both the default
 * and the max validation.
 */
final class CollectionPageRequestBoundsTest extends TestCase
{
    #[Test]
    public function declared_default_per_page_applies_when_omitted(): void
    {
        $request = CollectionPageRequest::fromQueryParams(null, null, defaultPerPage: 5, maxPerPage: 25);

        self::assertSame(1, $request->page);
        self::assertSame(5, $request->perPage);
    }

    #[Test]
    public function declared_max_replaces_the_static_cap(): void
    {
        // Above the static MAX_PER_PAGE (50) but within the declared max.
        $request = CollectionPageRequest::fromQueryParams(null, '60', defaultPerPage: 10, maxPerPage: 100);

        self::assertSame(60, $request->perPage);
    }

    #[Test]
    public function declared_max_is_enforced_with_the_typed_400(): void
    {
        try {
            CollectionPageRequest::fromQueryParams(null, '26', defaultPerPage: 5, maxPerPage: 25);
            self::fail('expected InvalidPaginationException');
        } catch (InvalidPaginationException $e) {
            self::assertSame('perPage', $e->parameter);
            self::assertSame('must be <= 25', $e->reason);
        }
    }

    #[Test]
    public function static_defaults_are_unchanged(): void
    {
        $request = CollectionPageRequest::fromQueryParams(null, null);
        self::assertSame(CollectionPageRequest::DEFAULT_PER_PAGE, $request->perPage);

        $this->expectException(InvalidPaginationException::class);
        CollectionPageRequest::fromQueryParams(null, '51');
    }

    #[Test]
    public function constructor_honors_the_declared_max(): void
    {
        $request = new CollectionPageRequest(1, 60, maxPerPage: 100);
        self::assertSame(60, $request->perPage);

        $this->expectException(InvalidPaginationException::class);
        new CollectionPageRequest(1, 60);
    }
}
