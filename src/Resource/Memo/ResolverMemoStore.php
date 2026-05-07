<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Memo;

use Semitexa\Core\Resource\Metadata\ResourceFieldMetadata;
use Semitexa\Core\Resource\ResourceIdentity;
use Semitexa\Core\Resource\ResourceObjectInterface;

/**
 * Phase 7 (post-v1, internal): expansion-scoped resolver memo store.
 *
 * `ResourceExpansionPipeline` instantiates **one** of these per
 * `expand()` / `expandMany()` call. The memo collapses duplicate
 * resolver demand inside a single expansion flow:
 *
 *   - duplicate parent identities in `expandMany([$a, $a, $b], …)`
 *     resolve `$a` once;
 *   - overlapping include tokens (e.g. `profile` and
 *     `profile.preferences`) reuse the parent-relation result for
 *     the nested pass without a second `resolveBatch()`;
 *   - any future code path that re-asks for the same `(resolver,
 *     parent, field)` triple inside one expansion picks the
 *     memoised value up.
 *
 * Scope is **expansion-scoped**, not request-scoped. Two separate
 * `expand()` calls do not share memo state — a deliberate Swoole
 * safety choice: there is no static state, no container singleton
 * memo, and no implicit lifetime tied to the worker. Each
 * `ResourceExpansionPipeline::expandMany()` invocation creates a
 * fresh store and discards it on return.
 *
 * The store is **pure**: no DB, ORM, HTTP, IriBuilder, renderer, or
 * resolver references. It only holds validated values produced by
 * the pipeline (which itself validated the resolver's batch
 * return). Invalid resolver output never reaches the memo because
 * the pipeline raises `InvalidResolvedRelationException` before the
 * memo write site.
 *
 * Key shape — derived from the existing pipeline bucket key plus
 * the parent's urn:
 *
 *   resolverClass | parentUrn | fieldName | parentClass | targetClass
 *
 * The five fields together prevent every collision the per-phase
 * tests have asked about: same resolver across different parent
 * classes, same resolver across different relation fields, same
 * field name across different parent classes, etc.
 */
final class ResolverMemoStore
{
    /**
     * @var array<string, ResourceObjectInterface|list<ResourceObjectInterface>|null>
     *      keyed by {@see formatKey()}; values are the validated
     *      shapes the pipeline writes into `ResolvedResourceGraph`.
     */
    private array $entries = [];

    /**
     * Stable composite key. The store does not parse the key — it
     * only uses it for hash-map lookup — so the caller is free to
     * hash field collisions out by including more identifiers if
     * the shape ever needs to grow.
     *
     * @param class-string $parentClass
     */
    public static function formatKey(
        string $resolverClass,
        ResourceIdentity $parentIdentity,
        string $fieldName,
        string $parentClass,
        ?string $targetClass,
    ): string {
        return implode('|', [
            $resolverClass,
            $parentIdentity->urn(),
            $fieldName,
            $parentClass,
            $targetClass ?? '',
        ]);
    }

    /**
     * Convenience: derive the key directly from a pipeline bucket
     * field + parent identity + parent class. Mirrors what the
     * pipeline already computes per bucket.
     *
     * @param class-string $parentClass
     */
    public static function keyFromField(
        ResourceFieldMetadata $field,
        ResourceIdentity $parentIdentity,
        string $parentClass,
    ): string {
        \assert($field->resolverClass !== null);
        return self::formatKey(
            resolverClass:  $field->resolverClass,
            parentIdentity: $parentIdentity,
            fieldName:      $field->name,
            parentClass:    $parentClass,
            targetClass:    $field->target,
        );
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->entries);
    }

    /**
     * @return ResourceObjectInterface|list<ResourceObjectInterface>|null
     */
    public function get(string $key): mixed
    {
        if (!array_key_exists($key, $this->entries)) {
            throw new \LogicException(sprintf(
                'ResolverMemoStore: cannot get unknown key "%s"; '
                . 'caller must check has() first.',
                $key,
            ));
        }
        return $this->entries[$key];
    }

    /**
     * Store a **validated** value. The pipeline writes here only
     * after `extractValueFor()` has confirmed the shape; invalid
     * resolver output therefore never reaches the memo.
     *
     * @param ResourceObjectInterface|list<ResourceObjectInterface>|null $value
     */
    public function set(string $key, mixed $value): void
    {
        $this->entries[$key] = $value;
    }

    /**
     * Diagnostic only — exposed so static safety tests can assert
     * the memo is bounded by one expansion call.
     */
    public function size(): int
    {
        return count($this->entries);
    }
}
