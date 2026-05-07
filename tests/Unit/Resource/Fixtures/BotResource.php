<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Resource\Attribute\ResourceField;
use Semitexa\Core\Resource\Attribute\ResourceId;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\ResourceObjectInterface;

#[ResourceObject(type: 'bot')]
final readonly class BotResource implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId]
        public string $id,
        #[ResourceField]
        public string $version,
    ) {
    }
}
