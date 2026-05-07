<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

use InvalidArgumentException;

final readonly class ResourceRefList
{
    /** @param list<ResourceObjectInterface>|null $data */
    public function __construct(
        public ?array $data = null,
        public ?string $href = null,
        public ?int $total = null,
        public ?ResourcePageInfo $pageInfo = null,
    ) {
    }

    /**
     * Reference-only (not loaded). v1 requires a non-empty href so the
     * envelope is never an indistinguishable empty `{}` on the wire.
     * Handlers that have no canonical URL for a relation must use ::embed()
     * instead — even if the data is an empty array.
     */
    public static function to(string $href): self
    {
        if ($href === '') {
            throw new InvalidArgumentException(
                'ResourceRefList::to() requires a non-empty href. '
                . 'Use ::embed([]) for "no canonical URL, but embedded empty collection".',
            );
        }
        return new self(null, $href, null, null);
    }

    /** @param list<ResourceObjectInterface> $data */
    public static function embed(
        array $data,
        ?string $href = null,
        ?int $total = null,
        ?ResourcePageInfo $pageInfo = null,
    ): self {
        return new self($data, $href, $total, $pageInfo);
    }

    public function isLoaded(): bool
    {
        return $this->data !== null;
    }
}
