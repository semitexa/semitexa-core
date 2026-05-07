<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Resource\Attribute\ResourceField;
use Semitexa\Core\Resource\Attribute\ResourceId;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\ResourceObjectInterface;

#[ResourceObject(type: 'malformed-domain-factory')]
final readonly class MalformedDomainFactoryResource implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId]
        public string $id,
        #[ResourceField]
        public string $name,
    ) {
    }

    public static function fromDomain(SyntheticDomainEntity $entity): self
    {
        return new self($entity->id, 'projected');
    }
}
