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
 * MAPPER CONTRACT, not by a directory, so these fixtures deliberately put a mapper
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
