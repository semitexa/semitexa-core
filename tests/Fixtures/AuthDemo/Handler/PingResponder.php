<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\AuthDemo\Handler;

use Semitexa\Auth\Context\AuthContextStore;
use Semitexa\Core\Http\Response\ResourceResponse;

/** What every fixture ping answers: the route and who, if anyone, it ran as. */
final class PingResponder
{
    public static function respond(ResourceResponse $resource, string $route): ResourceResponse
    {
        return $resource
            ->setHeader('Content-Type', 'application/json')
            ->setContent((string) json_encode([
                'route' => $route,
                'principal' => AuthContextStore::getUser()?->getId(),
            ]));
    }
}
