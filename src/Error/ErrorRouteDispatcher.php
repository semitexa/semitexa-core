<?php

declare(strict_types=1);

namespace Semitexa\Core\Error;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Auth\AuthBootstrapperInterface;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Auth\GuestAuthContext;
use Semitexa\Core\Cookie\CookieJar;
use Semitexa\Core\Cookie\CookieJarInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Discovery\HandlerRegistry;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\Environment;
use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Exception\PipelineException;
use Semitexa\Core\Http\ErrorRenderer;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Pipeline\RouteExecutor;
use Semitexa\Core\Request;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\Session\Session;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Session\TransientSessionHandler;

final class ErrorRouteDispatcher
{
    public const ROUTE_NAME_404 = 'error.404';
    public const ROUTE_NAME_500 = 'error.500';

    private \Closure $routeExecutor;

    public function __construct(
        private readonly RouteRegistry $routeRegistry,
        private readonly RequestScopedContainer $requestScopedContainer,
        private readonly ContainerInterface $container,
        private readonly ?AuthBootstrapperInterface $authBootstrapper,
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
     * @param array<string, mixed>|null $currentRoute
     */
    public function dispatchStatus(int $statusCode, Request $request, ?array $currentRoute = null): ?HttpResponse
    {
        return $this->dispatch($statusCode, $request, $currentRoute, null);
    }

    /**
     * @param array<string, mixed>|null $currentRoute
     */
    public function dispatchThrowable(\Throwable $throwable, Request $request, ?array $currentRoute = null): ?HttpResponse
    {
        $statusCode = $throwable instanceof DomainException
            ? $throwable->getStatusCode()->value
            : HttpStatus::InternalServerError->value;

        return $this->dispatch($statusCode, $request, $currentRoute, $throwable);
    }

    /**
     * @param array<string, mixed>|null $currentRoute
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
            /** @var HandlerRegistry|null $handlerRegistry */
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
            $this->ensureGuestExecutionContext($request);
            $this->requestScopedContainer->set(ErrorPageContext::class, $context);
            ErrorPageContextStore::push($context);

            $response = ($this->routeExecutor)($route, $request);
            if (!$response instanceof HttpResponse) {
                throw new PipelineException('Error route executor must return an instance of HttpResponse.');
            }

            return $this->withStatusCode($response, $context->statusCode);
        } catch (\Throwable $routeFailure) {
            // Show and record what actually went wrong. Rendering only the error
            // page's own failure hid the cause: with Redis down the page said
            // "Cannot inject AuthContext" and the connection error was logged
            // nowhere at all.
            StaticLoggerBridge::error('core', 'Error page failed to render; served the built-in page instead', [
                'route' => $routeName,
                'failure' => $routeFailure::class . ': ' . $routeFailure->getMessage(),
                'original' => $throwable !== null
                    ? $throwable::class . ': ' . $throwable->getMessage() . ' at ' . $throwable->getFile() . ':' . $throwable->getLine()
                    : null,
            ]);

            return ErrorRenderer::renderStatus(
                $this->buildContext(HttpStatus::InternalServerError->value, $request, $currentRoute, $throwable ?? $routeFailure),
                $request,
            );
        } finally {
            ErrorPageContextStore::pop();
            $state->leave($routeName);
        }
    }

    /**
     * A request that failed before or inside SessionPhase can lack a session,
     * a cookie jar or an identity, and every error route needs them (its auth
     * gate injects the auth context). Fill in only what is missing — a
     * signed-in visitor keeps their identity — as a guest on a session that
     * keeps nothing, instead of failing the error page a second time.
     */
    private function ensureGuestExecutionContext(Request $request): void
    {
        // holds(), not has(): has() falls through to the root container, which
        // knows the auth service but not this request's identity.
        if (!$this->requestScopedContainer->holds(AuthContextInterface::class)) {
            $this->requestScopedContainer->set(AuthContextInterface::class, GuestAuthContext::getInstance());
        }
        if (!$this->requestScopedContainer->holds(CookieJarInterface::class)) {
            $this->requestScopedContainer->set(CookieJarInterface::class, new CookieJar($request));
        }
        if (!$this->requestScopedContainer->holds(SessionInterface::class)) {
            $this->requestScopedContainer->set(
                SessionInterface::class,
                new Session(bin2hex(random_bytes(16)), new TransientSessionHandler(), 'semitexa_session'),
            );
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
     * @param array<string, mixed>|null $currentRoute
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

        // DomainException messages are part of the framework's user-facing
        // contract — exposing "Missing permission: users.manage" or
        // "Article 42 not found" is the whole point of the type. Generic
        // "An unexpected error occurred" stays reserved for non-domain
        // throwables (5xx leaks we must not surface).
        if ($throwable instanceof DomainException) {
            $publicMessage = $throwable->getMessage();
        } elseif ($normalizedStatus === HttpStatus::NotFound->value) {
            $publicMessage = 'The requested resource was not found.';
        } else {
            $publicMessage = 'An unexpected error occurred.';
        }

        $originalRouteName = is_array($currentRoute) && is_string($currentRoute['name'] ?? null)
            ? $currentRoute['name']
            : null;

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
            originalRouteName: $originalRouteName,
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
