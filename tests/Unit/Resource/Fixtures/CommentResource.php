<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Resource\Attribute\ResourceField;
use Semitexa\Core\Resource\Attribute\ResourceId;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\Attribute\ResourceUnion;
use Semitexa\Core\Resource\ResourceObjectInterface;
use Semitexa\Core\Resource\ResourceRef;
use Semitexa\Core\Resource\ResourceRefList;

#[ResourceObject(type: 'comment')]
final readonly class CommentResource implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId]
        public string $id,

        #[ResourceField]
        public string $body,

        #[ResourceUnion(targets: [UserResource::class, BotResource::class], discriminator: 'type')]
        public ResourceRef $author,

        #[ResourceUnion(targets: [UserResource::class, BotResource::class], discriminator: 'type', list: true, expandable: true)]
        public ResourceRefList $mentions,
    ) {
    }
}
