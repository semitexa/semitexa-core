<?php

declare(strict_types=1);

namespace Semitexa\Core\Exception;

use Semitexa\Core\Http\HttpStatus;

class AuthenticationException extends DomainException
{
    public function __construct(string $message = 'Authentication required.')
    {
        parent::__construct($message);
    }

    public function getStatusCode(): HttpStatus
    {
        return HttpStatus::Unauthorized;
    }
}
