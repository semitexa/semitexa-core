<?php

declare(strict_types=1);

namespace Semitexa\Core\Contract;

use Semitexa\Core\Resource\CollectionCriteria;
use Semitexa\Core\Resource\CompiledCollection;

/**
 * One Way Pattern: the criteria push-down seam.
 *
 * A compiler takes the validated intent of a collection read
 * ({@see CollectionCriteria}) and executes it AGAINST the source —
 * WHERE / ORDER BY / LIMIT at the source's native level, never
 * in-memory. The ORM implementation (semitexa-orm) compiles onto
 * `ResourceModelQuery`; the in-memory `apply()` helpers on the
 * wrapped request VOs remain the documented fallback for routes
 * without a compilable source.
 *
 * The seam lives in core so any source package can implement it; the
 * `$source` parameter is intentionally untyped beyond `object` —
 * core must not reference downstream query types. Implementations
 * gate via {@see supports()} and throw on an unsupported source.
 *
 * Same container-override pattern as the other core seams:
 * implementations register with
 * `#[SatisfiesServiceContract(of: CollectionQueryCompilerInterface::class)]`.
 */
interface CollectionQueryCompilerInterface
{
    public function supports(object $source): bool;

    /**
     * Compile and execute. `$fieldMap` translates API-level criteria
     * field names (sort/filter/search fields, `id`) to source property
     * names; unmapped names pass through verbatim.
     *
     * @param array<string, string> $fieldMap
     */
    public function compile(CollectionCriteria $criteria, object $source, array $fieldMap = []): CompiledCollection;
}
