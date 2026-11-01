<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Orm\Application\Service\Uuid7 as Identifier;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;

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
