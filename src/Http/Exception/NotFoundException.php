<?php

declare(strict_types=1);

namespace Semitexa\Core\Http\Exception;

use Semitexa\Core\Http\HttpStatus;

/**
 * Thrown when a resource is not found. The system will treat this as HTTP 404
 * and may dispatch to the route named error.404 (if registered), so modules
 * like core-frontend can render a custom 404 page.
 *
 * @deprecated Use Semitexa\Core\Exception\NotFoundException instead.
 */
class NotFoundException extends \Semitexa\Core\Exception\DomainException
{
    public function __construct(string $message = 'Not Found')
    {
        parent::__construct($message);
    }

    public function getStatusCode(): HttpStatus
    {
        return HttpStatus::NotFound;
    }
}
