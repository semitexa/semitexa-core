<?php

declare(strict_types=1);

namespace Semitexa\Core\Exception;

use Semitexa\Core\Http\HttpStatus;

class ValidationException extends DomainException
{
    /**
     * @param array<string, list<string>> $errors Field-level validation errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Validation failed.');
    }

    public function getStatusCode(): HttpStatus
    {
        return HttpStatus::UnprocessableEntity;
    }

    public function getErrorContext(): array
    {
        return ['errors' => $this->errors];
    }
}
