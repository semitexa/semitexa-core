<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Pagination\CollectionPage;
use Semitexa\Core\Resource\Pagination\CollectionPageRequest;

/**
 * One Way Phase 2: the optional `meta.pagination.mode` discriminator
 * on page envelopes. Null mode keeps the Phase 6i envelope
 * byte-identical (key order included); a declared mode leads the
 * envelope, mirroring the cursor shape.
 */
final class CollectionPageModeTest extends TestCase
{
    #[Test]
    public function null_mode_keeps_the_legacy_envelope_byte_identical(): void
    {
        $page = CollectionPage::compute(new CollectionPageRequest(2, 5), 12);

        self::assertNull($page->mode);
        self::assertSame(
            ['page' => 2, 'perPage' => 5, 'total' => 12, 'pageCount' => 3, 'hasNext' => true, 'hasPrevious' => true],
            $page->toArray(),
        );
    }

    #[Test]
    public function declared_mode_leads_the_envelope(): void
    {
        $page = CollectionPage::compute(new CollectionPageRequest(1, 5), 3, mode: 'page');

        self::assertSame('page', $page->mode);
        self::assertSame(
            ['mode', 'page', 'perPage', 'total', 'pageCount', 'hasNext', 'hasPrevious'],
            array_keys($page->toArray()),
        );
        self::assertSame('page', $page->toArray()['mode']);
    }
}
