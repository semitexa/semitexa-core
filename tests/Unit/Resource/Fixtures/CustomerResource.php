<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Resource\Attribute\ResourceField;
use Semitexa\Core\Resource\Attribute\ResourceId;
use Semitexa\Core\Resource\Attribute\ResourceListOf;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\Attribute\ResourceRef as ResourceRefAttr;
use Semitexa\Core\Resource\Attribute\ResourceRefList as ResourceRefListAttr;
use Semitexa\Core\Resource\ResourceObjectInterface;
use Semitexa\Core\Resource\ResourceRef;
use Semitexa\Core\Resource\ResourceRefList;

#[ResourceObject(type: 'customer')]
final readonly class CustomerResource implements ResourceObjectInterface
{
    /**
     * @param array<int, AddressResource> $tags
     */
    public function __construct(
        #[ResourceId]
        public string $id,

        #[ResourceField(description: 'Public customer name')]
        public string $name,

        #[ResourceRefAttr(target: ProfileResource::class, expandable: true, include: 'profile', href: '/customers/{id}/profile')]
        public ?ResourceRef $profile,

        #[ResourceRefListAttr(
            target: AddressResource::class,
            expandable: true,
            include: 'addresses',
            href: '/customers/{id}/addresses',
        )]
        public ResourceRefList $addresses,

        #[ResourceListOf(target: AddressResource::class)]
        public array $tags = [],
    ) {
    }
}
