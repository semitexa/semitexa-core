<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Contract\RouteResponseDecoratorInterface;
use Semitexa\Core\Discovery\ResolvedRouteMetadata;
use Semitexa\Core\Request;
use Semitexa\Core\HttpResponse;

#[SatisfiesServiceContract(of: RouteResponseDecoratorInterface::class)]
final class DefaultRouteResponseDecorator implements RouteResponseDecoratorInterface
{
    public function decorate(HttpResponse $response, Request $request, ResolvedRouteMetadata $metadata): HttpResponse
    {
        return $response;
    }
}
