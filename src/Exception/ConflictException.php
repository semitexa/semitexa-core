<?php

declare(strict_types=1);

namespace Semitexa\Core\Exception;

use Semitexa\Core\Http\HttpStatus;

class ConflictException extends DomainException
{
    public function __construct(string $message = 'Resource conflict.')
    {
        parent::__construct($message);
    }

    public function getStatusCode(): HttpStatus
    {
        return HttpStatus::Conflict;
    }
}
