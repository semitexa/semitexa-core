<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

use InvalidArgumentException;

final readonly class ResourceIdentity
{
    public function __construct(
        public string $type,
        public string $id,
    ) {
        if ($type === '') {
            throw new InvalidArgumentException('ResourceIdentity::$type must be a non-empty string.');
        }
        if ($id === '') {
            throw new InvalidArgumentException('ResourceIdentity::$id must be a non-empty string.');
        }
    }

    public static function of(string $type, string $id): self
    {
        return new self($type, $id);
    }

    public function urn(): string
    {
        return 'urn:semitexa:' . $this->type . ':' . $this->id;
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }
}
