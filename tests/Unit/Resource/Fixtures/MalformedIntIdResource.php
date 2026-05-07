<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Resource\Attribute\ResourceId;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\ResourceObjectInterface;

#[ResourceObject(type: 'malformed-int-id')]
final readonly class MalformedIntIdResource implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId]
        public int $id,
    ) {
    }
}
