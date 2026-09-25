<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Resource\Attribute\ResolveWith;
use Semitexa\Core\Resource\Attribute\ResourceId;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\Attribute\ResourceUnion;
use Semitexa\Core\Resource\ResourceObjectInterface;
use Semitexa\Core\Resource\ResourceRef;

/**
 * Fixture: a resolver-backed polymorphic relation whose targets differ
 * in their nested relations and id property names.
 */
#[ResourceObject(type: 'union_comment')]
final readonly class UnionCommentResource implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId]
        public string $id,

        #[ResourceUnion(
            targets: [UnionUserResource::class, UnionBotResource::class, UnionKeyedBotResource::class],
            discriminator: 'type',
            expandable: true,
            include: 'author',
        )]
        #[ResolveWith(UnionAuthorResolver::class)]
        public ?ResourceRef $author = null,
    ) {
    }
}
