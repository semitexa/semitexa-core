<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Resource\Attribute\ResolveWith;
use Semitexa\Core\Resource\Attribute\ResourceId;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\Attribute\ResourceRef as ResourceRefAttr;
use Semitexa\Core\Resource\ResourceObjectInterface;
use Semitexa\Core\Resource\ResourceRef;

/**
 * Fixture: first target of `UnionCommentResource::$author`. Only this
 * target declares the resolver-backed `profile` relation.
 */
#[ResourceObject(type: 'union_user')]
final readonly class UnionUserResource implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId]
        public string $id,

        #[ResourceRefAttr(target: ProfileResource::class, expandable: true, include: 'profile', href: '/users/{id}/profile')]
        #[ResolveWith(RecordingProfileResolver::class)]
        public ?ResourceRef $profile = null,
    ) {
    }
}
