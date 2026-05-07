<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Metadata;

final readonly class ResourceFieldMetadata
{
    /**
     * @param list<class-string>|null $unionTargets
     */
    public function __construct(
        public string $name,
        public ResourceFieldKind $kind,
        public bool $nullable,
        public ?string $target = null,
        public ?string $include = null,
        public ?string $hrefTemplate = null,
        public bool $expandable = false,
        public bool $paginated = false,
        public bool $list = false,
        public string $description = '',
        public bool $deprecated = false,
        public ?array $unionTargets = null,
        public ?string $discriminator = null,
        /**
         * Phase 6b: FQCN of the `RelationResolverInterface` implementation
         * that loads this relation when the future expansion pipeline
         * (Phase 6d+) is asked to expand it. `null` means "no resolver
         * declared" — the relation is either eager-loaded by the handler
         * or simply rendered as a link.
         */
        public ?string $resolverClass = null,
    ) {
    }

    public function isRelation(): bool
    {
        return match ($this->kind) {
            ResourceFieldKind::RefOne,
            ResourceFieldKind::RefMany,
            ResourceFieldKind::Union => true,
            default => false,
        };
    }

    public function isList(): bool
    {
        return $this->list;
    }
}
