<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Resource\Attribute\ResolveWith;
use Semitexa\Core\Resource\Attribute\ResourceId;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\Attribute\ResourceRef as ResourceRefAttr;
use Semitexa\Core\Resource\ResourceObjectInterface;
use Semitexa\Core\Resource\ResourceRef;

#[ResourceObject(type: 'malformed_resolver_without_as_service')]
final readonly class MalformedResolverWithoutAsServiceResource implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId]
        public string $id,

        // StubResolverWithoutAsService implements the interface but is
        // not marked #[AsService] — must fail validation.
        #[ResourceRefAttr(target: ProfileResource::class, expandable: true, include: 'profile', href: '/x/{id}/profile')]
        #[ResolveWith(StubResolverWithoutAsService::class)]
        public ?ResourceRef $profile,
    ) {
    }
}
