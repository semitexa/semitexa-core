<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Orm\Application\Service\Uuid7 as Identifier;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;

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
