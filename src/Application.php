<?php

declare(strict_types=1);

namespace Semitexa\Core;

use Semitexa\Auth\AuthBootstrapper;
use Semitexa\Core\Http\RouteType;
use Semitexa\Core\Pipeline\RouteExecutor;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Container\RequestScopedContainer;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Core\Cookie\CookieJar;
use Semitexa\Core\Cookie\CookieJarInterface;
use Semitexa\Core\Session\RedisSessionHandler;
use Semitexa\Core\Session\Session;
use Semitexa\Core\Session\SessionHandlerInterface;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Session\SwooleTableSessionHandler;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Tenant\DefaultTenantContext;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Locale\LocaleContextInterface;
use Semitexa\Core\Locale\DefaultLocaleContext;
use Semitexa\Locale\LocaleBootstrapper;
use Semitexa\Locale\Context\LocaleContextStore;
use Semitexa\Tenancy\Context\CoroutineContextStore;
use Semitexa\Tenancy\Context\TenantContext as TenancyTenantContext;
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
        return $fn();
    }

    public Environment $environment {
        get {
            return $this->environment;
        }
    }

    private ContainerInterface $container {
        get {
            return $this->container;
        }
    }

    public RequestScopedContainer $requestScopedContainer {
        get {
            return $this->requestScopedContainer;
        }
    }

    private ?TenancyBootstrapper $tenancy = null;

    /** @var AuthBootstrapper|null */
    private ?AuthBootstrapper $authBootstrapper = null;

    /** @var LocaleBootstrapper|null */
    private ?LocaleBootstrapper $localeBootstrapper = null;

    public function __construct(?ContainerInterface $container = null)
    {
        $this->container = $container ?? \Semitexa\Core\Container\ContainerFactory::get();
        $this->requestScopedContainer = \Semitexa\Core\Container\ContainerFactory::createRequestScoped();
        $this->environment = $this->container->get(Environment::class);

        $events = null;
        try {
            $events = $this->container->get(EventDispatcherInterface::class);
        } catch (\Throwable) {
            // EventDispatcher not registered
        }

        $this->tenancy = new TenancyBootstrapper($events);

        if (class_exists(AuthBootstrapper::class)) {
            $this->authBootstrapper = new AuthBootstrapper($this->container, $events, $this->requestScopedContainer);
        }

        if (class_exists(LocaleBootstrapper::class)) {
            $localeManager = new \Semitexa\Locale\Context\LocaleManager();
            $this->localeBootstrapper = new LocaleBootstrapper($localeManager, events: $events);
        }
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
        
            // Resolve tenant context via tenancy module (coroutine-safe, event-driven)
            if ($this->tenancy !== null && $this->tenancy->isEnabled()) {
                $tenantResponse = $this->tenancy->getHandler()->handle($request);
                if ($tenantResponse !== null) {
                    return $tenantResponse; // Short-circuit: tenant not found or required
                }
            }

            // Session and cookies (request-scoped; handlers inject SessionInterface / CookieJarInterface)
            $this->initSessionAndCookies($request);

            // Locale resolution (Tenant → Locale order; locale can use request path/header)
            $localeRedirect = $this->resolveLocaleAndUpdateContainer($request);
            if ($localeRedirect !== null) {
                return $this->finalizeSessionAndCookies($request, $localeRedirect);
            }

            // Initialize attribute discovery
            AttributeDiscovery::initialize();

            // Try to find route using AttributeDiscovery
            $routingPath = $this->getRoutingPath($request);
            $route = AttributeDiscovery::findRoute($routingPath, $request->getMethod());
            $this->debugLog('H1', 'Application::handleRequest', 'route_discovery', [
                'path' => $routingPath,
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
                if ($routingPath === '/' || $routingPath === '') {
                    $altPath = $routingPath === '/' ? '' : '/';
                    $route = AttributeDiscovery::findRoute($altPath, $request->getMethod());
                    if ($route) {
                        $response = $this->handleRoute($route, $request);
                    } else {
                        $response = $this->helloWorld($request);
                    }
                } else {
                    if ($routingPath === '/sse' && $request->getMethod() === 'GET') {
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
                $type = $route['type'] ?? null;
                
                if ($type === RouteType::HttpRequest->value) {
                    $executor = new RouteExecutor(
                        $this->requestScopedContainer, 
                        $this->container, 
                        $this->authBootstrapper
                    );
                    return $executor->execute($route, $request);
                }

                if ($type === RouteType::Legacy->value || ($route['class'] ?? null && $route['method'] ?? null)) {
                    return $this->handleLegacyRoute($route);
                }

                throw new \RuntimeException('Unknown route type: ' . ($type ?? 'undefined'));
            } catch (\Throwable $e) {
                return $this->handleRouteException($e, $route, $request);
            }
        });
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
        $logger = null;
        try {
            $logger = $this->container->get(\Semitexa\Core\Log\LoggerInterface::class);
        } catch (\Throwable) {
            // Logger not available
        }

        if ($e instanceof \Semitexa\Core\Http\Exception\NotFoundException) {
            if ($logger) {
                $logger->debug('Route not found', ['path' => $request->getPath(), 'message' => $e->getMessage()]);
            }

            $currentRouteName = $route['name'] ?? null;
            if ($currentRouteName !== self::ROUTE_NAME_404) {
                $route404 = \Semitexa\Core\Discovery\AttributeDiscovery::findRouteByName(self::ROUTE_NAME_404);
                if ($route404 !== null) {
                    return $this->handleRoute($route404, $request);
                }
            }
            return Response::notFound($e->getMessage() ?: 'The requested resource was not found');
        }

        if ($logger) {
            $logger->error($e->getMessage(), [
                'exception' => get_debug_type($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $this->environment->appDebug ? $e->getTraceAsString() : 'hidden',
            ]);
        } else {
            // Fallback for critical errors when logger fails
            error_log("[Semitexa] Critical Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
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
        $handler = self::createSessionHandler();
        $handlerType = $handler instanceof \Semitexa\Core\Session\RedisSessionHandler ? 'redis' : 'swoole_table';
        $session = new Session($sessionId, $handler, $cookieName, $sessionLifetime);
        $this->requestScopedContainer->set(SessionInterface::class, $session);
        $this->requestScopedContainer->set(CookieJarInterface::class, new CookieJar($request));
        $this->requestScopedContainer->set(Request::class, $request);

        $this->initContextInterfaces();

        \Semitexa\Core\Debug\SessionDebugLog::log('Application.initSessionAndCookies', [
            'path' => $request->getPath(),
            'method' => $request->getMethod(),
            'handler' => $handlerType,
            'session_id_source' => $fromCookie ? 'from_cookie' : 'new',
            'session_id_preview' => substr($sessionId, 0, 8) . '…',
            'cookie_name' => $cookieName,
            'has_auth_user_id' => $session->has('_auth_user_id'),
        ]);
    }

    private static function createSessionHandler(): SessionHandlerInterface
    {
        $redisHost = Environment::getEnvValue('REDIS_HOST');
        if ($redisHost !== null && $redisHost !== '') {
            return new RedisSessionHandler();
        }
        return new SwooleTableSessionHandler();
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
            'path' => $request->getPath(),
            'method' => $request->getMethod(),
            'session_id_preview' => substr($session->getSessionIdForCookie(), 0, 8) . '…',
            'has_auth_user_id' => $session->has('_auth_user_id'),
            'jar_line_count' => count($linesBeforeSession),
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
            'path' => $request->getPath(),
            'set_cookie_count' => count($lines),
            'set_cookie_names' => $cookieNamesFromLines,
            'session_id_preview' => substr($session->getSessionIdForCookie(), 0, 8) . '…',
        ]);
        return $response;
    }

    /**
     * Resolve locale from request and set LocaleContextInterface in container.
     * Called after initSessionAndCookies so order is Tenant → Locale.
     */
    private function resolveLocaleAndUpdateContainer(Request $request): ?Response
    {
        if ($this->localeBootstrapper === null || !$this->localeBootstrapper->isEnabled()) {
            return null;
        }

        $cookieJar = $this->requestScopedContainer->has(CookieJarInterface::class)
            ? $this->requestScopedContainer->get(CookieJarInterface::class)
            : null;

        $resolution = $this->localeBootstrapper->resolve($request, $cookieJar);
        $this->requestScopedContainer->set(LocaleContextInterface::class, $this->localeBootstrapper->getLocaleContext());

        $config = $this->localeBootstrapper->getConfig();

        LocaleContextStore::setUrlPrefixEnabled($config->urlPrefixEnabled);
        LocaleContextStore::setDefaultLocale($config->defaultLocale);

        // 301 redirect: /{defaultLocale}/path → /path (GET/HEAD only)
        if ($resolution !== null
            && $resolution->hadPathPrefix
            && $resolution->locale === $config->defaultLocale
            && $config->urlPrefixEnabled
            && $config->urlRedirectDefault
            && in_array($request->getMethod(), ['GET', 'HEAD'], true)
        ) {
            $target = $resolution->strippedPath;
            $qs = $request->getQueryString();
            if ($qs !== '') {
                $target .= '?' . $qs;
            }
            return new Response(301, '', ['Location' => $target]);
        }

        // Store stripped path for routing
        if ($resolution !== null && $resolution->strippedPath !== null && $config->urlPrefixEnabled) {
            $this->requestScopedContainer->set('locale.strippedPath', $resolution->strippedPath);
        }

        return null;
    }

    private function getRoutingPath(Request $request): string
    {
        if ($this->requestScopedContainer->has('locale.strippedPath')) {
            return $this->requestScopedContainer->get('locale.strippedPath');
        }

        return $request->getPath();
    }

    /**
     * Initialize request-scoped context interfaces (Tenant, Auth, Locale).
     * These are set into RequestScopedContainer for injection into handlers.
     * When tenancy is enabled, resolved context from CoroutineContextStore is used
     * and synced to TenantContext::set() so ORM and TenantContextInterface::get() see it.
     */
    private function initContextInterfaces(): void
    {
        $tenantContext = DefaultTenantContext::getInstance();
        if ($this->tenancy !== null && $this->tenancy->isEnabled()) {
            $resolved = CoroutineContextStore::get();
            if ($resolved !== null) {
                $tenantContext = $resolved;
                TenancyTenantContext::set($resolved);
            } else {
                TenancyTenantContext::clear();
            }
        } else {
            TenancyTenantContext::clear();
        }
        $this->requestScopedContainer->set(TenantContextInterface::class, $tenantContext);

        $authContext = \Semitexa\Auth\Context\AuthManager::getInstance();
        $this->requestScopedContainer->set(AuthContextInterface::class, $authContext);

        $localeContext = DefaultLocaleContext::getInstance();
        $this->requestScopedContainer->set(LocaleContextInterface::class, $localeContext);
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
        $logDir = \Semitexa\Core\Util\ProjectRoot::get() . '/var/log';
        if (is_dir($logDir)) {
            @file_put_contents(
                $logDir . '/debug.log',
                json_encode($payload) . "\n",
                FILE_APPEND
            );
        }
        // #endregion
    }
}
