<?php

declare(strict_types=1);

namespace Semitexa\Core\Lifecycle;

use Psr\Container\ContainerInterface;
use Semitexa\Auth\AuthBootstrapper;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Environment;
use Semitexa\Core\Http\RouteType;
use Semitexa\Core\Pipeline\RouteExecutor;
use Semitexa\Core\Request;
use Semitexa\Core\HttpResponse;

/**
 * @internal Matches the request to a route and executes it.
 */
final class RoutePhase
{
    /** Route name for custom 404 page */
    public const ROUTE_NAME_404 = 'error.404';

    private readonly AttributeDiscovery $attributeDiscovery;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly RequestScopedContainer $requestScopedContainer,
        private readonly ?AuthBootstrapper $authBootstrapper,
        private readonly Environment $environment,
    ) {
        $this->attributeDiscovery = $this->container->get(AttributeDiscovery::class);
    }

    public function execute(RequestLifecycleContext $context): HttpResponse
    {
        $request = $context->request;
        $routingPath = $context->getRoutingPath();

        $route = $this->attributeDiscovery->findRoute($routingPath, $request->getMethod());

        if ($route) {
            return $this->handleRoute($route, $request);
        }

        // Try alternate root path normalization ('/' vs '')
        if ($routingPath === '/' || $routingPath === '') {
            $altPath = $routingPath === '/' ? '' : '/';
            $route = $this->attributeDiscovery->findRoute($altPath, $request->getMethod());
            if ($route) {
                return $this->handleRoute($route, $request);
            }
        }

        return $this->getNotFoundResponse($request);
    }

    /**
     * @param array{type?: string, class?: string, handlers?: list<array{class?: string, execution?: string}>, responseClass?: string, method?: string, name?: string} $route
     */
    private function handleRoute(array $route, Request $request): HttpResponse
    {
        try {
            $type = $route['type'] ?? null;

            if ($type === RouteType::HttpRequest->value) {
                $executor = new RouteExecutor(
                    $this->requestScopedContainer,
                    $this->container,
                    $this->authBootstrapper
                );
                return $executor->execute($route, $request);
            }

            throw new \RuntimeException('Unknown route type: ' . ($type ?? 'undefined'));
        } catch (\Throwable $e) {
            return $this->handleRouteException($e, $route, $request);
        }
    }

    /**
     * @param array{name?: string} $route
     */
    private function handleRouteException(\Throwable $e, array $route, Request $request): HttpResponse
    {
        $logger = $this->container->has(\Semitexa\Core\Log\LoggerInterface::class)
            ? $this->container->get(\Semitexa\Core\Log\LoggerInterface::class)
            : null;

        if ($e instanceof \Semitexa\Core\Exception\NotFoundException) {
            if ($logger instanceof \Semitexa\Core\Log\LoggerInterface) {
                $logger->debug('Route not found', ['path' => $request->getPath(), 'message' => $e->getMessage()]);
            }

            $currentRouteName = $route['name'] ?? null;
            if ($currentRouteName !== self::ROUTE_NAME_404) {
                $route404 = $this->attributeDiscovery->findRouteByName(self::ROUTE_NAME_404);
                if ($route404 !== null) {
                    return $this->handleRoute($route404, $request);
                }
            }
            return HttpResponse::notFound($e->getMessage() ?: 'The requested resource was not found');
        }

        if ($logger instanceof \Semitexa\Core\Log\LoggerInterface) {
            $logger->error($e->getMessage(), [
                'exception' => get_debug_type($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $this->environment->appDebug ? $e->getTraceAsString() : 'hidden',
            ]);
        } else {
            error_log("[Semitexa] Critical Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }

        return \Semitexa\Core\Http\ErrorRenderer::render($e, $request, $this->environment->appDebug);
    }

    private function getNotFoundResponse(Request $request): HttpResponse
    {
        $route404 = $this->attributeDiscovery->findRouteByName(self::ROUTE_NAME_404);
        if ($route404 !== null) {
            return $this->handleRoute($route404, $request);
        }
        return HttpResponse::notFound('The requested resource was not found');
    }
}
