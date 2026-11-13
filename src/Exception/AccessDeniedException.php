<?php

declare(strict_types=1);

namespace Semitexa\Core\Exception;

use Semitexa\Core\Http\HttpStatus;

/**
 * The caller may not do this; answered as 403.
 *
 * @api
 */
class AccessDeniedException extends DomainException
{
    public function __construct(string $message = 'Access denied.')
    {
        parent::__construct($message);
    }

    public function getStatusCode(): HttpStatus
    {
        return HttpStatus::Forbidden;
    }
}
