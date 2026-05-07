<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

final readonly class ResourceRef
{
    public function __construct(
        public ResourceIdentity $identity,
        public ?ResourceObjectInterface $data = null,
        public ?string $href = null,
    ) {
    }

    public static function to(ResourceIdentity $identity, ?string $href = null): self
    {
        return new self($identity, null, $href);
    }

    public static function embed(
        ResourceIdentity $identity,
        ResourceObjectInterface $data,
        ?string $href = null,
    ): self {
        return new self($identity, $data, $href);
    }

    public function isLoaded(): bool
    {
        return $this->data !== null;
    }
}
