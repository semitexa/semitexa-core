<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

use Semitexa\Core\Attributes\SatisfiesServiceContract;
use Semitexa\Core\Contract\RouteResponseDecoratorInterface;
use Semitexa\Core\Discovery\ResolvedRouteMetadata;
use Semitexa\Core\Request;
use Semitexa\Core\Response;

#[SatisfiesServiceContract(of: RouteResponseDecoratorInterface::class)]
final class DefaultRouteResponseDecorator implements RouteResponseDecoratorInterface
{
    public function decorate(Response $response, Request $request, ResolvedRouteMetadata $metadata): Response
    {
        return $response;
    }
}
