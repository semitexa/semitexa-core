<?php

declare(strict_types=1);

namespace Semitexa\Core\Exception;

use Semitexa\Core\Http\HttpStatus;

/**
 * What the request asks for does not exist; answered as 404.
 *
 * @api
 */
class NotFoundException extends DomainException
{
    public function __construct(string $entity, string|int $id)
    {
        parent::__construct("{$entity} #{$id} not found.");
    }

    public function getStatusCode(): HttpStatus
    {
        return HttpStatus::NotFound;
    }
}
