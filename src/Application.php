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
use Semitexa\Core\Tenancy\TenantResolver;
use Semitexa\Core\Tenancy\TenantContext;
use DI\Container;
use Semitexa\Core\Container\RequestScopedContainer;
/**
 * Minimal Semitexa Application
 */
class Application
{
    private static function measure(string $label, callable $fn): mixed
    {
        if (class_exists(\Semitexa\Inspector\Profiler::class)) {
            return \Semitexa\Inspector\Profiler::measure($label, $fn);
        }
        return $fn();
    }
    private Environment $environment;
    private Container $container;
    private RequestScopedContainer $requestScopedContainer;
    
    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? \Semitexa\Core\Container\ContainerFactory::get();
        $this->requestScopedContainer = \Semitexa\Core\Container\ContainerFactory::getRequestScoped();
        $this->environment = $this->container->get(Environment::class);
    }
    
    public function getContainer(): Container
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
        
        // Resolve tenant context (request-scoped, prevents data leakage)
        $tenantResolver = new TenantResolver($this->environment);
        $tenantContext = $tenantResolver->resolve($request);
        
        // Store tenant context in request-scoped container for access during request handling
        $this->requestScopedContainer->setTenantContext($tenantContext);

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
                $response = $this->notFound($request);
            }
        }

        return $this->finalizeSessionAndCookies($request, $response);
        });
    }
    
    private function handleRoute(array $route, Request $request): Response
    {
        return self::measure('Application::handleRoute', function() use ($route, $request) {
            try {
            // Request/Handler flow
            if (($route['type'] ?? null) === 'http-request') {
                $runId = 'initial';
                $requestClass = $route['class'];
                $responseClass = $route['responseClass'] ?? null;
                $handlerClasses = $route['handlers'] ?? [];
                $segmentStart = microtime(true);

                // Instantiate DTOs
                $reqDto = class_exists($requestClass) ? new $requestClass() : null;
                if (!$reqDto) {
                    throw new \RuntimeException("Cannot instantiate request class: {$requestClass}");
                }
                
                // Hydrate Request DTO from HTTP Request data
                try {
                    $reqDto = \Semitexa\Core\Http\RequestDtoHydrator::hydrate($reqDto, $request);
                    // Allow DTO to access HTTP Request if it has setHttpRequest method
                    if (method_exists($reqDto, 'setHttpRequest')) {
                        $reqDto->setHttpRequest($request);
                    }
                } catch (\Throwable $e) {
                    // Continue with empty DTO if hydration fails
                }

                // Validate payload: strict types + constraint attributes. Reject invalid input with 422.
                $validationResult = \Semitexa\Core\Http\PayloadValidator::validate($reqDto, $request);
                if (!$validationResult->isValid()) {
                    return Response::json(['errors' => $validationResult->getErrors()], 422);
                }

                $this->debugLog('H2', 'Application::handleRoute', 'request_hydrated', [
                    'requestClass' => $requestClass,
                    'duration_ms' => round((microtime(true) - $segmentStart) * 1000, 2),
                ], $runId);
                $segmentStart = microtime(true);
                
                $resDto = ($responseClass && class_exists($responseClass)) ? new $responseClass() : null;

                // Fallback generic response if none supplied
                if ($resDto === null) {
                    $resDto = new \Semitexa\Core\Http\Response\GenericResponse();
                }

                // Apply AsResource defaults if present
                if ($resDto) {
                    $resolvedResponse = \Semitexa\Core\Discovery\AttributeDiscovery::getResolvedResponseAttributes(get_class($resDto));
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
                    // Fallback: try to read attribute directly (for cases where getResolvedResponseAttributes returns null)
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
                            // If still no handle, try parent class
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
                }

                // Execute handlers in order
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
                            $resDto
                        );
                        continue;
                    }

                    if (!class_exists($handlerClass)) {
                        continue;
                    }
                    
                    // Use request-scoped container to resolve handler (do not cache: handler must get
                    // the same CookieJar as in finalizeSessionAndCookies, so cookies set in handler are sent).
                    try {
                        $handlerStart = microtime(true);
                        $handler = $this->requestScopedContainer->get($handlerClass);
                        
                        // Verify that properties are injected (especially important in Swoole)
                        $reflection = new \ReflectionClass($handler);
                        foreach ($reflection->getProperties() as $property) {
                            $attributes = $property->getAttributes(\DI\Attribute\Inject::class);
                            if (!empty($attributes)) {
                                $property->setAccessible(true);
                                $value = $property->getValue($handler);
                                if ($value === null) {
                                    throw new \RuntimeException(
                                        "Property {$property->getName()} in {$handlerClass} was not injected. " .
                                        "This usually means injectOn() failed or make() didn't inject properties."
                                    );
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        // Don't fallback to direct instantiation - it won't work with property injection
                        // Instead, throw the error so we can see what's wrong
                        throw new \RuntimeException("Failed to resolve handler {$handlerClass}: " . $e->getMessage(), 0, $e);
                    }
                    
                    if (method_exists($handler, 'handle')) {
                        $resDto = $handler->handle($reqDto, $resDto);
                        $this->debugLog('H2', 'Application::handleRoute', 'handler_completed', [
                            'handler' => $handlerClass,
                            'duration_ms' => round((microtime(true) - $handlerStart) * 1000, 2),
                        ], $runId);
                    }
                }

                // Centralized rendering step (if requested by handlers)
                if (method_exists($resDto, 'getRenderHandle')) {
                    $handle = $resDto->getRenderHandle();
                    if ($handle) {
                        $renderStart = microtime(true);
                        $context = method_exists($resDto, 'getRenderContext') ? $resDto->getRenderContext() : [];
                        $format = method_exists($resDto, 'getRenderFormat') ? $resDto->getRenderFormat() : null;
                        if ($format === null) {
                            // default to layout when handle provided
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
                            // Use provided renderer or default LayoutRenderer (from semitexa/core-frontend)
                            $renderer = $rendererClass ?: 'Semitexa\\Frontend\\Layout\\LayoutRenderer';
                            if (!class_exists($renderer)) {
                                throw new \RuntimeException(
                                    'LayoutRenderer not found. For HTML pages install semitexa/core-frontend: composer require semitexa/core-frontend. Do not implement a custom Twig renderer in the project.'
                                );
                            }
                            if (!method_exists($renderer, 'renderHandle')) {
                                throw new \RuntimeException(
                                    'LayoutRenderer::renderHandle not found. Use semitexa/core-frontend for HTML rendering. Do not implement a custom renderer in the project.'
                                );
                            }
                            // Automatically wrap context in 'response' key if not already present
                            // This allows templates to access context as response.error, response.data, etc.
                            if (!isset($context['response'])) {
                                $context = ['response' => $context] + $context;
                            }
                            // Add request to context if available
                            if (!isset($context['request']) && isset($reqDto)) {
                                $context['request'] = $reqDto;
                            }
                            // Layout frame (e.g. one-column) for layout-level slots
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
                        } else {
                            // raw/no-op
                        }
                        $this->debugLog('H3', 'Application::handleRoute', 'render_completed', [
                            'handle' => $handle,
                            'format' => is_object($format) && property_exists($format, 'value') ? $format->value : $format,
                            'renderer' => $rendererClass ?: ($renderer ?? null),
                            'duration_ms' => round((microtime(true) - $renderStart) * 1000, 2),
                        ], $runId);
                    }
                }

                // Adapt to core Response
                // If handler returned a Core Response directly, use it
                if ($resDto instanceof \Semitexa\Core\Response) {
                    return $resDto;
                }
                
                // If response DTO has toCoreResponse method, use it
                if (method_exists($resDto, 'toCoreResponse')) {
                    return $resDto->toCoreResponse();
                }
                
                // Generic fallback
                return Response::json(['ok' => true]);
            }

            // Legacy controller flow
            $controller = new $route['class']();
            $method = $route['method'];
            $response = $method === '__invoke' ? $controller() : $controller->$method();
            return $response;
        } catch (\Throwable $e) {
            try {
                $this->container->get(LoggerInterface::class)->error($e->getMessage(), [
                    'exception' => get_debug_type($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            } catch (\Throwable) {
                // avoid failing error handling when logger is unavailable
            }
            return \Semitexa\Core\Http\ErrorRenderer::render($e, $request);
        }
        });
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
