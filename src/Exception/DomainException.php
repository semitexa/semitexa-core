<?php

declare(strict_types=1);

namespace Semitexa\Core\Exception;

use Semitexa\Core\Http\HttpStatus;

/**
 * Base class for domain exceptions that map to HTTP status codes.
 * Handlers throw these instead of returning Response objects.
 */
abstract class DomainException extends \RuntimeException
{
    abstract public function getStatusCode(): HttpStatus;

    /**
     * Machine-readable error code for API consumers.
     * Default: snake_case of class short name without "Exception" suffix.
     */
    public function getErrorCode(): string
    {
        $class = (new \ReflectionClass($this))->getShortName();
        $name = preg_replace('/Exception$/', '', $class);

        return strtolower(preg_replace('/[A-Z]/', '_$0', lcfirst($name)));
    }

    /**
     * Additional context merged into the error response body.
     *
     * @return array<string, mixed>
     */
    public function getErrorContext(): array
    {
        return [];
    }
}
