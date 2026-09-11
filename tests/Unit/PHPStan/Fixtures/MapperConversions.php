<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Orm\Application\Service\Uuid7 as Identifier;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;

/**
 * Fixture: what MapperTypeConversionRule must and must not flag.
 *
 * Read as a pair with the rule's own scope decision — the rule is scoped by the
 * MAPPER CONTRACT, not by a directory, so this file deliberately puts a mapper
 * and a non-mapper side by side in one namespace that is neither package's
 * `Application/Db`.
 */

/** FLAGGED — a mapper undoing the hydrator's work in both directions. */
final class FlaggedMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        return (object) ['id' => Uuid7::fromBytes($resourceModel->id)];
    }

    public function toSourceModel(object $domainModel): object
    {
        return (object) ['id' => Uuid7::toBytes($domainModel->id)];
    }
}

/** FLAGGED — an aliased import is the same call; the rule resolves names in scope. */
final class AliasedMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        return (object) ['id' => Identifier::fromBytes($resourceModel->id)];
    }

    public function toSourceModel(object $domainModel): object
    {
        return $domainModel;
    }
}

/** ALLOWED — a mapper doing what a mapper owns: a storage shape the column type cannot express. */
final class ShapeOnlyMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        return (object) ['tags' => json_decode($resourceModel->tags, true)];
    }

    public function toSourceModel(object $domainModel): object
    {
        return (object) ['tags' => json_encode($domainModel->tags)];
    }
}

/**
 * ALLOWED — not a mapper. A repository binds a raw WHERE value that nothing
 * hydrates, so it converts legitimately and must stay out of scope.
 */
final class ArticleRepository
{
    public function findById(string $id): array
    {
        return ['id' => Uuid7::toBytes($id)];
    }
}
