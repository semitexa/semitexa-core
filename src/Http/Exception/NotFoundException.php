<?php

declare(strict_types=1);

namespace Semitexa\Core\Http\Exception;

/**
 * Compatibility alias for \Semitexa\Core\Exception\NotFoundException.
 *
 * Thrown when a resource is not found. Application::handleRouteException()
 * may dispatch to the route named error.404 when this exception bubbles up.
 *
 * @deprecated Use \Semitexa\Core\Exception\NotFoundException instead.
 */
class NotFoundException extends \Semitexa\Core\Exception\NotFoundException
{
    public function __construct(string $entity = 'Resource', string|int $id = 0)
    {
        parent::__construct($entity, $id);
    }
}
