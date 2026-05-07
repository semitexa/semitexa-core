<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

use InvalidArgumentException;

final readonly class ResourcePageInfo
{
    public function __construct(
        public int $page,
        public int $perPage,
        public ?string $nextHref = null,
        public ?string $prevHref = null,
    ) {
        if ($page < 1) {
            throw new InvalidArgumentException('ResourcePageInfo::$page must be >= 1.');
        }
        if ($perPage < 1) {
            throw new InvalidArgumentException('ResourcePageInfo::$perPage must be >= 1.');
        }
    }
}
