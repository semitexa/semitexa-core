<?php

declare(strict_types=1);

namespace Semitexa\Core\Lifecycle;

use Psr\Container\ContainerInterface;
use Semitexa\Auth\AuthBootstrapper;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Discovery\HandlerRegistry;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\Environment;
use Semitexa\Core\Error\ErrorRouteDispatcher;
use Semitexa\Core\Http\RouteType;
use Semitexa\Core\Pipeline\RouteExecutor;
use Semitexa\Core\Request;
use Semitexa\Core\Exception\RoutingException;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\Log\FallbackErrorLogger;

/**
 * @internal Matches the request to a route and executes it.
 */
final class RoutePhase
{
    /** Route name for custom 404 page */
    public const ROUTE_NAME_404 = ErrorRouteDispatcher::ROUTE_NAME_404;
    public const ROUTE_NAME_500 = ErrorRouteDispatcher::ROUTE_NAME_500;

    private readonly RouteRegistry $routeRegistry;
    private readonly ErrorRouteDispatcher $errorRouteDispatcher;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly RequestScopedContainer $requestScopedContainer,
        private readonly ?AuthBootstrapper $authBootstrapper,
        private readonly Environment $environment,
    ) {
        /** @var RouteRegistry $routeRegistry */
        $routeRegistry = $this->container->get(RouteRegistry::class);
        $this->routeRegistry = $routeRegistry;
        $this->errorRouteDispatcher = new ErrorRouteDispatcher(
            $routeRegistry,
            $this->requestScopedContainer,
            $this->container,
            $this->authBootstrapper,
            $this->environment,
        );
    }

    public function execute(RequestLifecycleContext $context): HttpResponse
    {
        $request = $context->request;
        $routingPath = $context->getRoutingPath();

        /** @var HandlerRegistry|null $handlerRegistry */
        $handlerRegistry = $this->container->has(HandlerRegistry::class)
            ? $this->container->get(HandlerRegistry::class)
            : null;

        $route = $this->routeRegistry->findRouteTyped($routingPath, $request->getMethod(), $handlerRegistry);

        if ($route) {
            return $this->handleRoute($route, $request);
        }

        // Try alternate root path normalization ('/' vs '')
        if ($routingPath === '/' || $routingPath === '') {
            $altPath = $routingPath === '/' ? '' : '/';
            $route = $this->routeRegistry->findRouteTyped($altPath, $request->getMethod(), $handlerRegistry);
            if ($route) {
                return $this->handleRoute($route, $request);
            }
        }

        return $this->getNotFoundResponse($request);
    }

    private function handleRoute(DiscoveredRoute $route, Request $request): HttpResponse
    {
        try {
            if ($route->type === RouteType::HttpRequest->value) {
                $executor = new RouteExecutor(
                    $this->requestScopedContainer,
                    $this->container,
                    $this->authBootstrapper
                );
                return $executor->execute($route, $request);
            }

            throw new RoutingException('Unknown route type: ' . $route->type);
        } catch (\Throwable $e) {
            return $this->handleRouteException($e, $route, $request);
        }
    }

    /**
     * @param DiscoveredRoute|null $route
     */
    private function handleRouteException(\Throwable $e, ?DiscoveredRoute $route, Request $request): HttpResponse
    {
        $logger = $this->container->has(\Semitexa\Core\Log\LoggerInterface::class)
            ? $this->container->get(\Semitexa\Core\Log\LoggerInterface::class)
            : null;

        if ($e instanceof \Semitexa\Core\Exception\NotFoundException) {
            if ($logger instanceof \Semitexa\Core\Log\LoggerInterface) {
                $logger->debug('Route not found', ['path' => $request->getPath(), 'message' => $e->getMessage()]);
            }

            $response = $this->errorRouteDispatcher->dispatchThrowable($e, $request, $route?->toArray());
            if ($response !== null) {
                return $response;
            }
            return HttpResponse::notFound($e->getMessage() ?: 'The requested resource was not found');
        }

        if ($logger instanceof \Semitexa\Core\Log\LoggerInterface) {
            $context = [
                'exception' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
            if ($this->environment->appDebug) {
                $context['trace'] = $e->getTraceAsString();
            }
            $logger->error($e->getMessage(), $context);
        } else {
            FallbackErrorLogger::log('Critical error', [
                'message' => $e->getMessage(),
                'exception' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }

        $response = $this->errorRouteDispatcher->dispatchThrowable($e, $request, $route?->toArray());
        if ($response !== null) {
            return $response;
        }

        return \Semitexa\Core\Http\ErrorRenderer::render($e, $request, $this->environment->appDebug);
    }

    private function getNotFoundResponse(Request $request): HttpResponse
    {
        $response = $this->errorRouteDispatcher->dispatchStatus(404, $request);
        if ($response !== null) {
            return $response;
        }
        return HttpResponse::notFound('The requested resource was not found');
    }

    /**
     * @param array<string, mixed>|null $currentRoute
     */
    public function renderErrorThrowable(\Throwable $throwable, Request $request, ?array $currentRoute = null): ?HttpResponse
    {
        return $this->errorRouteDispatcher->dispatchThrowable($throwable, $request, $currentRoute);
    }

    /**
     * @param array<string, mixed>|null $currentRoute
     */
    public function renderErrorStatus(int $statusCode, Request $request, ?array $currentRoute = null): ?HttpResponse
    {
        return $this->errorRouteDispatcher->dispatchStatus($statusCode, $request, $currentRoute);
    }
}
