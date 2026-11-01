<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Orm\Application\Service\Uuid7 as Identifier;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;

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
