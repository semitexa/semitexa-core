<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\CollectionEnvelope;
use Semitexa\Core\Resource\Cursor\CollectionCursorPage;
use Semitexa\Core\Resource\Exception\MalformedCollectionEnvelopeException;
use Semitexa\Core\Resource\Pagination\CollectionPage;

/**
 * The envelope's two directions, and the guarantee that they stay each other's inverse.
 *
 * `toArray()` and `fromArray()` are between them the ONLY declaration of this wire shape —
 * there is no schema file. Nothing but a round trip can stop them drifting apart, and a
 * drift would be silent: the producer would keep emitting a valid response that the reader
 * quietly refuses, or worse, misreads.
 */
final class CollectionEnvelopeTest extends TestCase
{
    #[Test]
    public function offset_pagination_survives_a_round_trip(): void
    {
        $page = new CollectionPage(
            page: 2, perPage: 25, total: 137, pageCount: 6, hasNext: true, hasPrevious: true, mode: 'page',
        );

        self::assertEquals($page, CollectionPage::fromArray($page->toArray()));
    }

    #[Test]
    public function offset_pagination_without_a_declared_policy_survives_a_round_trip(): void
    {
        // mode is omitted entirely for routes with no #[CollectionPaginated] policy, which
        // keeps the envelope byte-identical. The reader has to accept that absence.
        $page = new CollectionPage(
            page: 1, perPage: 10, total: 0, pageCount: 0, hasNext: false, hasPrevious: false,
        );

        $restored = CollectionPage::fromArray($page->toArray());

        self::assertEquals($page, $restored);
        self::assertNull($restored->mode);
    }

    #[Test]
    public function cursor_pagination_survives_a_round_trip(): void
    {
        $cursor = new CollectionCursorPage(
            perPage: 20, total: 91, hasNext: true, nextCursor: 'eyJpZCI6NDJ9', cursor: null,
        );

        self::assertEquals($cursor, CollectionCursorPage::fromArray($cursor->toArray()));
    }

    #[Test]
    public function a_null_next_cursor_round_trips_as_null_rather_than_vanishing(): void
    {
        // present-but-null is the "no next page" signal clients branch on; losing the key
        // would turn it into a missing-key error at the far end.
        $cursor = new CollectionCursorPage(
            perPage: 20, total: 3, hasNext: false, nextCursor: null, cursor: 'abc',
        );

        $restored = CollectionCursorPage::fromArray($cursor->toArray());

        self::assertNull($restored->nextCursor);
        self::assertSame('abc', $restored->cursor);
    }

    #[Test]
    public function the_mode_key_decides_which_page_type_a_response_carries(): void
    {
        $cursor = CollectionEnvelope::fromArray([
            'data' => [],
            'meta' => ['pagination' => (new CollectionCursorPage(5, 5, false, null, null))->toArray()],
        ]);
        $offset = CollectionEnvelope::fromArray([
            'data' => [],
            'meta' => ['pagination' => (new CollectionPage(1, 5, 5, 1, false, false, 'page'))->toArray()],
        ]);

        self::assertInstanceOf(CollectionCursorPage::class, $cursor->pagination);
        self::assertInstanceOf(CollectionPage::class, $offset->pagination);
    }

    #[Test]
    public function it_reads_a_real_json_body_the_way_a_test_would(): void
    {
        $json = json_encode([
            'data' => [
                ['id' => 'uiping_1', 'label' => 'ping · test · 1'],
                ['id' => 'uiping_2', 'label' => 'ping · test · 2'],
            ],
            'meta' => [
                'pagination' => ['mode' => 'page', 'page' => 1, 'perPage' => 5, 'total' => 6,
                                 'pageCount' => 2, 'hasNext' => true, 'hasPrevious' => false],
                'filterOptions' => ['status' => ['open', 'closed']],
            ],
        ], \JSON_THROW_ON_ERROR);

        $envelope = CollectionEnvelope::fromJson($json);

        self::assertSame(2, $envelope->count());
        self::assertSame('uiping_1', $envelope->item(0)['id']);
        self::assertSame(6, $envelope->page()->total);
        self::assertSame('page', $envelope->page()->mode);
        self::assertTrue($envelope->page()->hasNext);
        self::assertSame(['status' => ['open', 'closed']], $envelope->filterOptions);
    }

    #[Test]
    public function an_envelope_without_meta_is_valid_and_simply_has_no_pagination(): void
    {
        $envelope = CollectionEnvelope::fromArray(['data' => [['id' => 'x']]]);

        self::assertNull($envelope->pagination);
        self::assertSame([], $envelope->filterOptions);
        self::assertSame(1, $envelope->count());
    }

    #[Test]
    public function asking_for_the_wrong_pagination_mode_says_which_one_the_response_has(): void
    {
        $envelope = CollectionEnvelope::fromArray([
            'data' => [],
            'meta' => ['pagination' => (new CollectionCursorPage(5, 5, false, null, null))->toArray()],
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cursor pagination');

        $envelope->page();
    }

    #[Test]
    public function a_missing_data_key_is_rejected_rather_than_read_as_empty(): void
    {
        // Silently treating a malformed body as an empty collection is how an assertion
        // like assertCount(0, ...) passes against a 500.
        $this->expectException(MalformedCollectionEnvelopeException::class);
        $this->expectExceptionMessage('missing "data"');

        CollectionEnvelope::fromArray(['meta' => []]);
    }

    #[Test]
    public function a_truncated_pagination_block_names_the_missing_key(): void
    {
        $this->expectException(MalformedCollectionEnvelopeException::class);
        $this->expectExceptionMessage('missing "pageCount"');

        CollectionEnvelope::fromArray([
            'data' => [],
            'meta' => ['pagination' => ['page' => 1, 'perPage' => 5, 'total' => 5]],
        ]);
    }

    #[Test]
    public function a_non_json_body_is_reported_as_such(): void
    {
        $this->expectException(MalformedCollectionEnvelopeException::class);
        $this->expectExceptionMessage('not decodable JSON');

        CollectionEnvelope::fromJson('<html>500 Internal Server Error</html>');
    }

    #[Test]
    public function out_of_range_item_access_throws_instead_of_returning_null(): void
    {
        $envelope = CollectionEnvelope::fromArray(['data' => [['id' => 'only']]]);

        $this->expectException(\OutOfRangeException::class);
        $this->expectExceptionMessage('1 item(s) present');

        $envelope->item(3);
    }
}
