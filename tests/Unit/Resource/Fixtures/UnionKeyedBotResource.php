<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Resource\Attribute\ResourceField;
use Semitexa\Core\Resource\Attribute\ResourceId;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\ResourceObjectInterface;

/**
 * Fixture: union target whose id property is not named `id`.
 */
#[ResourceObject(type: 'union_keyed_bot')]
final readonly class UnionKeyedBotResource implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId]
        public string $botKey,
        #[ResourceField]
        public string $version = '1',
    ) {
    }
}
