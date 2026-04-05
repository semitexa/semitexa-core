<?php

declare(strict_types=1);

namespace Semitexa\Core\Error;

use Psr\Container\ContainerInterface;
use Semitexa\Auth\AuthBootstrapper;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Discovery\HandlerRegistry;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\Environment;
use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Exception\PipelineException;
use Semitexa\Core\Http\ErrorRenderer;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Pipeline\RouteExecutor;
use Semitexa\Core\Request;
use Semitexa\Core\HttpResponse;

final class ErrorRouteDispatcher
{
    public const ROUTE_NAME_404 = 'error.404';
    public const ROUTE_NAME_500 = 'error.500';

    private \Closure $routeExecutor;

    public function __construct(
        private readonly RouteRegistry $routeRegistry,
        private readonly RequestScopedContainer $requestScopedContainer,
        private readonly ContainerInterface $container,
        private readonly ?AuthBootstrapper $authBootstrapper,
        private readonly Environment $environment,
        ?\Closure $routeExecutor = null,
    ) {
        $defaultRouteExecutor = function (DiscoveredRoute $route, Request $request): HttpResponse {
            $executor = new RouteExecutor(
                $this->requestScopedContainer,
                $this->container,
                $this->authBootstrapper,
            );

            return $executor->execute($route, $request);
        };

        $this->routeExecutor = $routeExecutor ?? $defaultRouteExecutor;
    }

    /**
     * @param array{name?: string}|null $currentRoute
     */
    public function dispatchStatus(int $statusCode, Request $request, ?array $currentRoute = null): ?HttpResponse
    {
        return $this->dispatch($statusCode, $request, $currentRoute, null);
    }

    /**
     * @param array{name?: string}|null $currentRoute
     */
    public function dispatchThrowable(\Throwable $throwable, Request $request, ?array $currentRoute = null): ?HttpResponse
    {
        $statusCode = $throwable instanceof DomainException
            ? $throwable->getStatusCode()->value
            : HttpStatus::InternalServerError->value;

        return $this->dispatch($statusCode, $request, $currentRoute, $throwable);
    }

    /**
     * @param array{name?: string}|null $currentRoute
     */
    private function dispatch(
        int $statusCode,
        Request $request,
        ?array $currentRoute,
        ?\Throwable $throwable,
    ): ?HttpResponse {
        if (!$this->prefersHtml($request)) {
            return null;
        }

        $routeName = $this->mapStatusToRouteName($statusCode);
        $context = $this->buildContext($statusCode, $request, $currentRoute, $throwable);

        if ($routeName === null) {
            return ErrorRenderer::renderStatus($context, $request);
        }

        $currentRouteName = $currentRoute['name'] ?? null;
        if ($currentRouteName === $routeName) {
            return ErrorRenderer::renderStatus(
                $this->buildContext(HttpStatus::InternalServerError->value, $request, $currentRoute, $throwable),
                $request,
            );
        }

        try {
            $handlerRegistry = $this->container->has(HandlerRegistry::class)
                ? $this->container->get(HandlerRegistry::class)
                : null;
            $route = $this->routeRegistry->findByNameTyped($routeName, $handlerRegistry);
        } catch (\Throwable) {
            return ErrorRenderer::renderStatus($context, $request);
        }

        if ($route === null) {
            return ErrorRenderer::renderStatus($context, $request);
        }

        $state = $this->getDispatchState();
        if (!$state->enter($routeName)) {
            return ErrorRenderer::renderStatus(
                $this->buildContext(HttpStatus::InternalServerError->value, $request, $currentRoute, $throwable),
                $request,
            );
        }

        try {
            $this->requestScopedContainer->set(Request::class, $request);
            $this->requestScopedContainer->set(ErrorPageContext::class, $context);
            ErrorPageContextStore::push($context);

            $response = ($this->routeExecutor)($route, $request);
            if (!$response instanceof HttpResponse) {
                throw new PipelineException('Error route executor must return an instance of HttpResponse.');
            }

            return $this->withStatusCode($response, $context->statusCode);
        } catch (\Throwable $routeFailure) {
            return ErrorRenderer::renderStatus(
                $this->buildContext(HttpStatus::InternalServerError->value, $request, $currentRoute, $routeFailure),
                $request,
            );
        } finally {
            ErrorPageContextStore::pop();
            $state->leave($routeName);
        }
    }

    private function getDispatchState(): ErrorDispatchState
    {
        if ($this->requestScopedContainer->has(ErrorDispatchState::class)) {
            /** @var ErrorDispatchState $state */
            $state = $this->requestScopedContainer->get(ErrorDispatchState::class);

            return $state;
        }

        $state = new ErrorDispatchState();
        $this->requestScopedContainer->set(ErrorDispatchState::class, $state);

        return $state;
    }

    /**
     * @param array{name?: string}|null $currentRoute
     */
    private function buildContext(
        int $statusCode,
        Request $request,
        ?array $currentRoute,
        ?\Throwable $throwable,
    ): ErrorPageContext {
        $status = HttpStatus::tryFrom($statusCode) ?? HttpStatus::InternalServerError;
        $normalizedStatus = $status->value;
        $debugEnabled = $this->environment->isDebug();

        $publicMessage = $normalizedStatus === HttpStatus::NotFound->value
            ? 'The requested resource was not found.'
            : 'An unexpected error occurred.';

        return new ErrorPageContext(
            statusCode: $normalizedStatus,
            reasonPhrase: $status->reason(),
            publicMessage: $publicMessage,
            requestPath: $request->getPath(),
            requestMethod: $request->getMethod(),
            requestId: $request->getHeader('X-Request-ID') ?: null,
            debugEnabled: $debugEnabled,
            exceptionClass: $throwable !== null ? get_debug_type($throwable) : null,
            debugMessage: $debugEnabled && $throwable !== null ? $throwable->getMessage() : null,
            trace: $debugEnabled && $throwable !== null ? $throwable->getTraceAsString() : null,
            originalRouteName: $currentRoute['name'] ?? null,
        );
    }

    private function mapStatusToRouteName(int $statusCode): ?string
    {
        return match ($statusCode) {
            HttpStatus::NotFound->value => self::ROUTE_NAME_404,
            HttpStatus::InternalServerError->value => self::ROUTE_NAME_500,
            default => null,
        };
    }

    private function prefersHtml(Request $request): bool
    {
        $accept = strtolower(trim($request->getHeader('Accept') ?? ''));

        if ($accept === '') {
            return false;
        }

        return str_contains($accept, 'text/html')
            || str_contains($accept, 'application/xhtml+xml')
            || str_contains($accept, '*/*');
    }

    private function withStatusCode(HttpResponse $response, int $statusCode): HttpResponse
    {
        if ($response->statusCode === $statusCode) {
            return $response;
        }

        return new HttpResponse(
            content: $response->content,
            statusCode: $statusCode,
            headers: $response->headers,
            alreadySent: $response->alreadySent,
        );
    }
}
