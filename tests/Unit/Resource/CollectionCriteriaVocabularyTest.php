<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\CollectionCriteria;
use Semitexa\Core\Resource\CollectionPaginationPolicy;
use Semitexa\Core\Resource\CompiledCollection;
use Semitexa\Core\Resource\Cursor\CollectionCursorPage;
use Semitexa\Core\Resource\Filter\CollectionFilterRequest;
use Semitexa\Core\Resource\Pagination\CollectionPage;
use Semitexa\Core\Resource\Pagination\CollectionPageRequest;
use Semitexa\Core\Resource\Sort\CollectionSortRequest;

/**
 * One Way Phase 2: the criteria vocabulary value objects —
 * {@see CollectionPaginationPolicy} validation, the
 * {@see CollectionCriteria} helpers, and {@see CompiledCollection}
 * exclusivity.
 */
final class CollectionCriteriaVocabularyTest extends TestCase
{
    #[Test]
    public function default_policy_mirrors_the_static_bounds_and_is_undeclared(): void
    {
        $policy = CollectionPaginationPolicy::default();

        self::assertFalse($policy->declared);
        self::assertSame(CollectionPaginationPolicy::MODE_PAGE, $policy->mode);
        self::assertSame(CollectionPageRequest::DEFAULT_PER_PAGE, $policy->defaultPerPage);
        self::assertSame(CollectionPageRequest::MAX_PER_PAGE, $policy->maxPerPage);
        self::assertSame([], $policy->perPageOptions);
    }

    #[Test]
    public function unknown_mode_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CollectionPaginationPolicy(mode: 'windowed');
    }

    #[Test]
    public function default_per_page_must_stay_within_max(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CollectionPaginationPolicy(defaultPerPage: 30, maxPerPage: 25);
    }

    #[Test]
    public function per_page_options_must_stay_within_max(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CollectionPaginationPolicy(perPageOptions: [5, 50], maxPerPage: 25);
    }

    #[Test]
    public function count_threshold_must_be_positive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CollectionPaginationPolicy(countThreshold: 0);
    }

    #[Test]
    public function criteria_helpers_reflect_cursor_and_search_state(): void
    {
        $criteria = new CollectionCriteria(
            page:         CollectionPageRequest::fromQueryParams(null, null),
            sort:         CollectionSortRequest::fromQueryParam(null, []),
            filter:       CollectionFilterRequest::fromQueryParam(null, []),
            q:            'term',
            searchFields: ['label'],
            cursor:       'abc',
            policy:       CollectionPaginationPolicy::default(),
        );

        self::assertTrue($criteria->isCursorRequest());
        self::assertTrue($criteria->hasSearch());

        $noSearch = new CollectionCriteria(
            page:         $criteria->page,
            sort:         $criteria->sort,
            filter:       $criteria->filter,
            q:            'term',
            searchFields: [],
            cursor:       null,
            policy:       $criteria->policy,
        );

        self::assertFalse($noSearch->isCursorRequest());
        self::assertFalse($noSearch->hasSearch());
    }

    #[Test]
    public function compiled_collection_rejects_both_pagination_shapes(): void
    {
        $page = CollectionPage::compute(CollectionPageRequest::fromQueryParams(null, null), 1);
        $cursorPage = new CollectionCursorPage(perPage: 5, total: 1, hasNext: false, nextCursor: null, cursor: null);

        $this->expectException(\InvalidArgumentException::class);
        new CompiledCollection(items: [], page: $page, cursorPage: $cursorPage);
    }

    #[Test]
    public function compiled_collection_reports_the_effective_mode(): void
    {
        $cursorPage = new CollectionCursorPage(perPage: 5, total: 1, hasNext: false, nextCursor: null, cursor: null);
        self::assertSame('cursor', (new CompiledCollection(items: [], cursorPage: $cursorPage))->mode());

        $declared = CollectionPage::compute(CollectionPageRequest::fromQueryParams(null, null), 1, mode: 'page');
        self::assertSame('page', (new CompiledCollection(items: [], page: $declared))->mode());

        $undeclared = CollectionPage::compute(CollectionPageRequest::fromQueryParams(null, null), 1);
        self::assertSame('page', (new CompiledCollection(items: [], page: $undeclared))->mode());

        self::assertNull((new CompiledCollection(items: []))->mode());
    }
}
