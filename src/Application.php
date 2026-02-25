<?php

declare(strict_types=1);

namespace Semitexa\Core;

use Semitexa\Core\Cookie\CookieJar;
use Semitexa\Core\Cookie\CookieJarInterface;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Queue\HandlerExecution;
use Semitexa\Core\Queue\QueueDispatcher;
use Semitexa\Core\Session\Session;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Session\SwooleTableSessionHandler;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Tenancy\TenancyBootstrapper;
/**
 * Minimal Semitexa Application
 */
class Application
{
    /** Route name for custom 404 page; if a payload with this name is registered, its handlers are invoked instead of the default not-found response. */
    public const ROUTE_NAME_404 = 'error.404';

    private static function measure(string $label, callable $fn): mixed
    {
        if (class_exists(\Semitexa\Inspector\Profiler::class)) {
            return \Semitexa\Inspector\Profiler::measure($label, $fn);
        }
        return $fn();
    }
    private Environment $environment;
    private ContainerInterface $container;
    private RequestScopedContainer $requestScopedContainer;
    private ?TenancyBootstrapper $tenancy = null;

    public function __construct(?ContainerInterface $container = null)
    {
        $this->container = $container ?? \Semitexa\Core\Container\ContainerFactory::get();
        $this->requestScopedContainer = \Semitexa\Core\Container\ContainerFactory::createRequestScoped();
        $this->environment = $this->container->get(Environment::class);

        // Initialize tenancy module (built once per worker, reads .env config)
        $events = null;
        try {
            $events = $this->container->get(EventDispatcherInterface::class);
        } catch (\Throwable) {
            // EventDispatcher not registered — tenancy will work without events
        }
        $this->tenancy = new TenancyBootstrapper($events);
    }
    
    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }
    
    public function getRequestScopedContainer(): RequestScopedContainer
    {
        return $this->requestScopedContainer;
    }
    
    public function getEnvironment(): Environment
    {
        return $this->environment;
    }
    
    public function handleRequest(Request $request): Response
    {
        return self::measure('Application::handleRequest', function() use ($request) {
            $runId = 'initial';
        $segmentStart = microtime(true);
        $this->debugLog('H1', 'Application::handleRequest', 'request_received', [
            'path' => $request->getPath(),
            'method' => $request->getMethod(),
        ], $runId);
        
        // Clear superglobals for security (prevent accidental use of unvalidated data)
        \Semitexa\Core\Http\SecurityHelper::clearSuperglobals();
        
        // Resolve tenant context via tenancy module (coroutine-safe, event-driven)
        if ($this->tenancy !== null && $this->tenancy->isEnabled()) {
            $tenantResponse = $this->tenancy->getHandler()->handle($request);
            if ($tenantResponse !== null) {
                return $tenantResponse; // Short-circuit: tenant not found or required
            }
        }

        // Session and cookies (request-scoped; handlers inject SessionInterface / CookieJarInterface)
        $this->initSessionAndCookies($request);
        
        // Initialize attribute discovery
        AttributeDiscovery::initialize();
        
        // Try to find route using AttributeDiscovery
        $route = AttributeDiscovery::findRoute($request->getPath(), $request->getMethod());
        $this->debugLog('H1', 'Application::handleRequest', 'route_discovery', [
            'path' => $request->getPath(),
            'method' => $request->getMethod(),
            'routeFound' => (bool) $route,
            'duration_ms' => round((microtime(true) - $segmentStart) * 1000, 2),
        ], $runId);
        $segmentStart = microtime(true);
        
        // Route found or not - no debug output needed
        $response = null;
        if ($route) {
            $response = $this->handleRoute($route, $request);
        } else {
            $path = $request->getPath();
            if ($path === '/' || $path === '') {
                $altPath = $path === '/' ? '' : '/';
                $route = AttributeDiscovery::findRoute($altPath, $request->getMethod());
                if ($route) {
                    $response = $this->handleRoute($route, $request);
                } else {
                    $response = $this->helloWorld($request);
                }
            } else {
                $path = $request->getPath();
                if ($path === '/sse' && $request->getMethod() === 'GET') {
                    // SSE support requires semitexa/ssr package
                }
                $response = $this->getNotFoundResponse($request);
            }
        }

        return $this->finalizeSessionAndCookies($request, $response);
        });
    }
    
    /**
     * @param array{type?: string, class?: string, handlers?: list<array{class?: string, execution?: string}>, responseClass?: string, method?: string, name?: string} $route
     */
    private function handleRoute(array $route, Request $request): Response
    {
        return self::measure('Application::handleRoute', function() use ($route, $request) {
            try {
                if (($route['type'] ?? null) === 'http-request') {
                    [$reqDto, $validationResponse] = $this->hydrateRequestDto($route, $request);
                    if ($validationResponse) {
                        return $validationResponse;
                    }

                    $resDto = $this->resolveResponseDto($route);
                    $handlers = $route['handlers'] ?? [];
                    $class = $route['class'] ?? null;
                    if ($class !== null) {
                        $resDto = $this->executeHandlers($handlers, $class, $reqDto, $resDto, $request);
                    }
                    $resDto = $this->renderResponse($resDto, $reqDto);
                    return $this->adaptResponse($resDto);
                }

                $routeClass = $route['class'] ?? null;
                $routeMethod = $route['method'] ?? null;
                if ($routeClass !== null && $routeMethod !== null) {
                    return $this->handleLegacyRoute($route);
                }

                throw new \RuntimeException('Unknown route type: ' . ($route['type'] ?? 'undefined'));
            } catch (\Throwable $e) {
                return $this->handleRouteException($e, $route, $request);
            }
        });
    }

    /**
     * @param array{type?: string, class?: string, handlers?: list<array{class?: string, execution?: string}>, responseClass?: string, method?: string, name?: string} $route
     * @return array{0: object, 1: ?Response}
     */
    private function hydrateRequestDto(array $route, Request $request): array
    {
        $requestClass = $route['class'] ?? null;
        if ($requestClass === null) {
            throw new \RuntimeException('Route has no class defined');
        }
        $segmentStart = microtime(true);

        $reqDto = class_exists($requestClass) ? new $requestClass() : null;
        if (!$reqDto) {
            throw new \RuntimeException("Cannot instantiate request class: {$requestClass}");
        }

        try {
            $reqDto = \Semitexa\Core\Http\RequestDtoHydrator::hydrate($reqDto, $request);
            if (method_exists($reqDto, 'setHttpRequest')) {
                $reqDto->setHttpRequest($request);
            }
        } catch (\Throwable $e) {
            // Continue with empty DTO if hydration fails
        }

        $validationResult = \Semitexa\Core\Http\PayloadValidator::validate($reqDto, $request);
        if (!$validationResult->isValid()) {
            return [$reqDto, Response::json(['errors' => $validationResult->getErrors()], 422)];
        }

        $this->debugLog('H2', 'Application::handleRoute', 'request_hydrated', [
            'requestClass' => $requestClass,
            'duration_ms' => round((microtime(true) - $segmentStart) * 1000, 2),
        ], 'initial');

        return [$reqDto, null];
    }

    /**
     * @param array{type?: string, class?: string, handlers?: list<array{class?: string, execution?: string}>, responseClass?: string, method?: string, name?: string} $route
     */
    private function resolveResponseDto(array $route): object
    {
        $responseClass = $route['responseClass'] ?? null;
        $resDto = ($responseClass && class_exists($responseClass)) ? new $responseClass() : null;

        if ($resDto === null) {
            $resDto = new \Semitexa\Core\Http\Response\GenericResponse();
        }

        // Apply AsResource defaults from resolved attributes
        $resolvedResponse = AttributeDiscovery::getResolvedResponseAttributes(get_class($resDto));
        if ($resolvedResponse) {
            if (isset($resolvedResponse['handle']) && $resolvedResponse['handle'] && method_exists($resDto, 'setRenderHandle')) {
                $resDto->setRenderHandle($resolvedResponse['handle']);
            }
            if (isset($resolvedResponse['context']) && method_exists($resDto, 'setRenderContext')) {
                $resDto->setRenderContext($resolvedResponse['context']);
            }
            if (array_key_exists('format', $resolvedResponse) && method_exists($resDto, 'setRenderFormat')) {
                $resDto->setRenderFormat($resolvedResponse['format']);
            }
            if (isset($resolvedResponse['renderer']) && method_exists($resDto, 'setRendererClass')) {
                $resDto->setRendererClass($resolvedResponse['renderer']);
            }
        }

        // Fallback: try to read attribute directly (3-level: class → attribute → parent attribute)
        if (!method_exists($resDto, 'getRenderHandle') || !$resDto->getRenderHandle()) {
            try {
                $r = new \ReflectionClass($resDto);
                $attrs = $r->getAttributes(\Semitexa\Core\Attributes\AsResource::class);
                if (!empty($attrs)) {
                    $a = $attrs[0]->newInstance();
                    if (method_exists($resDto, 'setRenderHandle') && $a->handle) {
                        $resDto->setRenderHandle($a->handle);
                    }
                    if (method_exists($resDto, 'setRenderContext') && isset($a->context)) {
                        $resDto->setRenderContext($a->context);
                    }
                    if (method_exists($resDto, 'setRenderFormat') && $a->format) {
                        $resDto->setRenderFormat($a->format);
                    }
                    if (method_exists($resDto, 'setRendererClass') && $a->renderer) {
                        $resDto->setRendererClass($a->renderer);
                    }
                }
                if (!method_exists($resDto, 'getRenderHandle') || !$resDto->getRenderHandle()) {
                    $parent = $r->getParentClass();
                    if ($parent) {
                        $parentAttrs = $parent->getAttributes(\Semitexa\Core\Attributes\AsResource::class);
                        if (!empty($parentAttrs)) {
                            $parentAttr = $parentAttrs[0]->newInstance();
                            if (method_exists($resDto, 'setRenderHandle') && $parentAttr->handle) {
                                $resDto->setRenderHandle($parentAttr->handle);
                            }
                            if (method_exists($resDto, 'setRenderFormat') && $parentAttr->format) {
                                $resDto->setRenderFormat($parentAttr->format);
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return $resDto;
    }

    /**
     * @param list<array{class?: string, execution?: string}|string> $handlerClasses
     */
    private function executeHandlers(array $handlerClasses, string $requestClass, object $reqDto, object $resDto, Request $request): object
    {
        $sessionId = $this->getSessionIdForAsyncDelivery($request);

        foreach ($handlerClasses as $handlerMeta) {
            $handlerClass = is_array($handlerMeta) ? ($handlerMeta['class'] ?? null) : $handlerMeta;
            if (!$handlerClass) {
                continue;
            }

            $execution = $handlerMeta['execution'] ?? HandlerExecution::Sync->value;
            if ($execution === HandlerExecution::Async->value) {
                QueueDispatcher::enqueue(
                    is_array($handlerMeta) ? $handlerMeta : ['class' => $handlerClass, 'payload' => $requestClass],
                    $reqDto,
                    $resDto,
                    $sessionId
                );
                continue;
            }

            if (!class_exists($handlerClass)) {
                continue;
            }

            try {
                $handlerStart = microtime(true);
                $handler = $this->requestScopedContainer->get($handlerClass);
            } catch (\Throwable $e) {
                throw new \RuntimeException("Failed to resolve handler {$handlerClass}: " . $e->getMessage(), 0, $e);
            }

            if (method_exists($handler, 'handle') && is_object($handler)) {
                $resDto = $handler->handle($reqDto, $resDto);
                $this->debugLog('H2', 'Application::handleRoute', 'handler_completed', [
                    'handler' => $handlerClass,
                    'duration_ms' => round((microtime(true) - $handlerStart) * 1000, 2),
                ], 'initial');

                $this->dispatchHandlerCompletedEvent($handlerClass, $resDto);
            }
        }

        return $resDto;
    }

    private function renderResponse(object $resDto, ?object $reqDto): object
    {
        if (!method_exists($resDto, 'getRenderHandle')) {
            return $resDto;
        }

        $handle = $resDto->getRenderHandle();
        if (!$handle) {
            return $resDto;
        }

        $renderStart = microtime(true);
        $context = method_exists($resDto, 'getRenderContext') ? $resDto->getRenderContext() : [];
        $format = method_exists($resDto, 'getRenderFormat') ? $resDto->getRenderFormat() : null;
        if ($format === null) {
            $format = \Semitexa\Core\Http\Response\ResponseFormat::Layout;
        }
        $rendererClass = method_exists($resDto, 'getRendererClass') ? $resDto->getRendererClass() : null;

        if ($format === \Semitexa\Core\Http\Response\ResponseFormat::Json) {
            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (method_exists($resDto, 'setContent')) {
                $resDto->setContent($json ?: '');
            }
            if (method_exists($resDto, 'setHeader')) {
                $resDto->setHeader('Content-Type', 'application/json');
            }
        } elseif ($format === \Semitexa\Core\Http\Response\ResponseFormat::Layout) {
            $renderer = $rendererClass ?: 'Semitexa\\Ssr\\Layout\\LayoutRenderer';
            if (!class_exists($renderer)) {
                throw new \RuntimeException(
                    'LayoutRenderer not found. For HTML pages install semitexa/ssr: composer require semitexa/ssr. Do not implement a custom Twig renderer in the project.'
                );
            }
            if (!method_exists($renderer, 'renderHandle')) {
                throw new \RuntimeException(
                    'LayoutRenderer::renderHandle not found. Use semitexa/ssr for HTML rendering. Do not implement a custom renderer in the project.'
                );
            }
            if (!isset($context['response'])) {
                $context = ['response' => $context] + $context;
            }
            if (!isset($context['request']) && isset($reqDto)) {
                $context['request'] = $reqDto;
            }
            if (method_exists($resDto, 'getLayoutFrame') && $resDto->getLayoutFrame() !== null) {
                $context['layout_frame'] = $resDto->getLayoutFrame();
            }
            $html = $renderer::renderHandle($handle, $context);
            if (method_exists($resDto, 'setContent')) {
                $resDto->setContent($html);
            }
            if (method_exists($resDto, 'setHeader')) {
                $resDto->setHeader('Content-Type', 'text/html; charset=utf-8');
            }
        }

        $this->debugLog('H3', 'Application::handleRoute', 'render_completed', [
            'handle' => $handle,
            'format' => is_object($format) && property_exists($format, 'value') ? $format->value : $format,
            'renderer' => $rendererClass ?: ($renderer ?? null),
            'duration_ms' => round((microtime(true) - $renderStart) * 1000, 2),
        ], 'initial');

        return $resDto;
    }

    private function getSessionIdForAsyncDelivery(Request $request): string
    {
        $id = $request->getCookie('semitexa_sse_session', '');
        if ($id !== '') {
            return $id;
        }
        $id = $request->getCookie('PHPSESSID', '');
        if ($id !== '') {
            return $id;
        }
        return $request->getQuery('session_id', '');
    }

    private function dispatchHandlerCompletedEvent(string $handlerClass, object $resDto): void
    {
        try {
            $events = $this->container->get(EventDispatcherInterface::class);
        } catch (\Throwable) {
            return;
        }

        if (!$events instanceof EventDispatcherInterface) {
            return;
        }

        $handle = method_exists($resDto, 'getRenderHandle') ? $resDto->getRenderHandle() : null;
        
        if (!$handle) {
            return;
        }

        $event = new \Semitexa\Core\Events\HandlerCompleted(
            $handlerClass,
            $resDto,
            $handle
        );

        $events->dispatch($event);
    }

    private function adaptResponse(object $resDto): Response
    {
        if ($resDto instanceof Response) {
            return $resDto;
        }
        if (method_exists($resDto, 'toCoreResponse')) {
            return $resDto->toCoreResponse();
        }
        return Response::json(['ok' => true]);
    }

    /**
     * @param array{class: string, method: string, name?: string} $route
     */
    private function handleLegacyRoute(array $route): Response
    {
        /** @var object $controller */
        $controller = new $route['class']();
        $method = $route['method'];
        if ($method === '__invoke' && is_callable($controller)) {
            return $controller();
        }
        if (is_callable([$controller, $method])) {
            return $controller->$method();
        }
        throw new \RuntimeException("Controller method {$route['class']}::{$method} is not callable");
    }

    /**
     * @param array{name?: string} $route
     */
    private function handleRouteException(\Throwable $e, array $route, Request $request): Response
    {
        if ($e instanceof \Semitexa\Core\Http\Exception\NotFoundException) {
            $currentRouteName = $route['name'] ?? null;
            if ($currentRouteName !== self::ROUTE_NAME_404) {
                $route404 = AttributeDiscovery::findRouteByName(self::ROUTE_NAME_404);
                if ($route404 !== null) {
                    return $this->handleRoute($route404, $request);
                }
            }
            return Response::notFound($e->getMessage() ?: 'The requested resource was not found');
        }
        try {
            $this->container->get(LoggerInterface::class)->error($e->getMessage(), [
                'exception' => get_debug_type($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        } catch (\Throwable) {
            // avoid failing error handling when logger is unavailable
        }
        return \Semitexa\Core\Http\ErrorRenderer::render($e, $request, $this->environment->appDebug);
    }

    /**
     * Returns 404 response: if a route named error.404 is registered, dispatches to it;
     * otherwise returns the default not-found response.
     */
    private function getNotFoundResponse(Request $request): Response
    {
        $route404 = AttributeDiscovery::findRouteByName(self::ROUTE_NAME_404);
        if ($route404 !== null) {
            return $this->handleRoute($route404, $request);
        }
        return $this->notFound($request);
    }
    
    private function helloWorld(Request $request): Response
    {
        return Response::json([
            'message' => 'Hello World from Semitexa!',
            'framework' => $this->environment->get('APP_NAME', 'Semitexa'),
            'mode' => $this->detectRuntimeMode($request),
            'environment' => $this->environment->get('APP_ENV', 'prod'),
            'debug' => $this->environment->isDebug(),
            'method' => $request->getMethod(),
            'path' => $request->getPath(),
            'swoole_server' => $request->getServer('SWOOLE_SERVER', 'not-set'),
            'server_software' => $request->getServer('SERVER_SOFTWARE', 'not-set'),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    private function detectRuntimeMode(Request $request): string
    {
            return 'swoole';
    }
    
    private function notFound(Request $request): Response
    {
        return Response::notFound('The requested resource was not found');
    }

    private function initSessionAndCookies(Request $request): void
    {
        $cookieName = Environment::getEnvValue('SESSION_COOKIE_NAME') ?? 'semitexa_session';
        $sessionId = $request->getCookie($cookieName, '');
        $fromCookie = $sessionId !== '' && strlen($sessionId) === 32;
        if (!$fromCookie) {
            $sessionId = bin2hex(random_bytes(16));
        }
        $sessionLifetime = (int) (Environment::getEnvValue('SESSION_LIFETIME') ?? '3600');
        $handler = new SwooleTableSessionHandler();
        $session = new Session($sessionId, $handler, $cookieName, $sessionLifetime);
        $this->requestScopedContainer->set(SessionInterface::class, $session);
        $this->requestScopedContainer->set(CookieJarInterface::class, new CookieJar($request));
        $this->requestScopedContainer->set(Request::class, $request);

        \Semitexa\Core\Debug\SessionDebugLog::log('Application.initSessionAndCookies', [
            'session_id_source' => $fromCookie ? 'from_cookie' : 'new',
            'session_id_preview' => substr($sessionId, 0, 8) . '…',
            'cookie_name' => $cookieName,
        ]);
    }

    private function finalizeSessionAndCookies(Request $request, Response $response): Response
    {
        try {
            $session = $this->requestScopedContainer->get(SessionInterface::class);
            $cookieJar = $this->requestScopedContainer->get(CookieJarInterface::class);
        } catch (\Throwable) {
            return $response;
        }

        $session->save();

        $cookieName = $session->getCookieName();
        $sessionLifetime = (int) (Environment::getEnvValue('SESSION_LIFETIME') ?? '3600');
        $linesBeforeSession = $cookieJar->getSetCookieLines();
        \Semitexa\Core\Debug\SessionDebugLog::log('Application.finalizeSessionAndCookies.beforeAddSession', [
            'jar_line_count' => count($linesBeforeSession),
            'jar_line_previews' => array_map(fn ($l) => substr($l, 0, 50) . '…', $linesBeforeSession),
        ]);
        $cookieJar->set($cookieName, $session->getSessionIdForCookie(), [
            'path' => '/',
            'httpOnly' => true,
            'sameSite' => 'lax',
            'maxAge' => $sessionLifetime,
        ]);

        $lines = $cookieJar->getSetCookieLines();
        if ($lines !== []) {
            $response = $response->withHeaders(['Set-Cookie' => $lines]);
        }

        $cookieNamesFromLines = [];
        foreach ($lines as $line) {
            $eq = strpos($line, '=');
            $cookieNamesFromLines[] = $eq !== false ? rawurldecode(trim(substr($line, 0, $eq))) : '?';
        }
        \Semitexa\Core\Debug\SessionDebugLog::log('Application.finalizeSessionAndCookies', [
            'set_cookie_count' => count($lines),
            'set_cookie_names' => $cookieNamesFromLines,
            'session_id_preview' => substr($session->getSessionIdForCookie(), 0, 8) . '…',
        ]);
        return $response;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function debugLog(string $hypothesisId, string $location, string $message, array $data, string $runId): void
    {
        // #region agent log
        $payload = [
            'sessionId' => 'debug-session',
            'runId' => $runId,
            'hypothesisId' => $hypothesisId,
            'location' => $location,
            'message' => $message,
            'data' => $data,
            'timestamp' => (int) round(microtime(true) * 1000),
        ];
        $logDir = getcwd() ? (getcwd() . '/var/log') : null;
        if ($logDir && is_dir($logDir)) {
            @file_put_contents(
                $logDir . '/debug.log',
                json_encode($payload) . "\n",
                FILE_APPEND
            );
        }
        // #endregion
    }
}
