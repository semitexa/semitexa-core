<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Resource\Attribute\ResourceId;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\Attribute\ResourceRefList as ResourceRefListAttr;
use Semitexa\Core\Resource\ResourceObjectInterface;

#[ResourceObject(type: 'malformed-reflist-type')]
final readonly class MalformedRefListTypeResource implements ResourceObjectInterface
{
    /** @param list<AddressResource> $addresses */
    public function __construct(
        #[ResourceId]
        public string $id,

        #[ResourceRefListAttr(target: AddressResource::class)]
        public array $addresses,
    ) {
    }
}
