<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Resource\Attribute\ResolveWith;
use Semitexa\Core\Resource\Attribute\ResourceId;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\Attribute\ResourceRef as ResourceRefAttr;
use Semitexa\Core\Resource\ResourceObjectInterface;
use Semitexa\Core\Resource\ResourceRef;

#[ResourceObject(type: 'malformed_resolver_missing_class')]
final readonly class MalformedResolverMissingClassResource implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId]
        public string $id,

        #[ResourceRefAttr(target: ProfileResource::class, expandable: true, include: 'profile', href: '/x/{id}/profile')]
        // String literal (not ::class) so PHP does not eagerly fail to load
        // a non-existent class — the validator must catch this at lint time.
        #[ResolveWith('Semitexa\\Core\\Tests\\Unit\\Resource\\Fixtures\\NonExistentResolverClass')]
        public ?ResourceRef $profile,
    ) {
    }
}
