<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Attribute;

use Attribute;

/**
 * Phase 6b: declares which `RelationResolverInterface` implementation
 * loads a relation when the future expansion pipeline (Phase 6d+) is
 * asked to expand it.
 *
 * Pure metadata. The attribute carries a class-string only; it never
 * instantiates or invokes the resolver. Phase 6b ships the contract;
 * runtime expansion arrives in later phases.
 *
 *     #[ResourceRef(target: ProfileResource::class, expandable: true, include: 'profile')]
 *     #[ResolveWith(CustomerProfileResolver::class)]
 *     public ?ResourceRef $profile;
 *
 * `lint:resources` validates that the resolver class exists, implements
 * `Semitexa\Core\Resource\RelationResolverInterface`, is registered as
 * a `#[AsService]`, and that `#[ResolveWith]` is only used on relation
 * fields (`#[ResourceRef]`, `#[ResourceRefList]`, `#[ResourceUnion]`).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ResolveWith
{
    /** @param class-string $resolverClass */
    public function __construct(public readonly string $resolverClass)
    {
    }
}
