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
         * FQCN of the `RelationResolverInterface` implementation
         * that loads this relation when the future expansion pipeline
         * is asked to expand it. `null` means "no resolver
         * declared" — the relation is either eager-loaded by the handler
         * or simply rendered as a link.
         */
        public ?string $resolverClass = null,
        /**
         * PHP type name of a `Scalar` field as declared on the property
         * (`string`, `int`, `float`, `bool`, `array`, `mixed`, …), without
         * the nullability marker — that lives in `$nullable`. Lets schema
         * emitters (OpenAPI) publish the real wire type instead of guessing.
         * `null` for non-scalar kinds.
         */
        public ?string $scalarType = null,
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
