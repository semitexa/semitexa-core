<?php

declare(strict_types=1);

namespace Semitexa\Core;

use Semitexa\Auth\AuthBootstrapper;
use Semitexa\Core\Http\HttpStatus;
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
    private ?string $localeStrippedPath = null;
    private SessionHandlerInterface $sessionHandler;

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

        $this->sessionHandler = self::createSessionHandler();
    }

    public function handleRequest(Request $request): Response
    {
        return self::measure('Application::handleRequest', function() use ($request) {
            $this->localeStrippedPath = null;
            $runId = 'initial';
            $segmentStart = microtime(true);
            $this->debugLog('H1', 'Application::handleRequest', 'request_received', [
                'path' => $request->getPath(),
                'method' => $request->getMethod(),
            ], $runId);

            // Tenant resolution (can short-circuit)
            $tenantResponse = $this->resolveTenancy($request);
            if ($tenantResponse !== null) {
                return $tenantResponse;
            }

            // Session and cookies
            $this->initSessionAndCookies($request);

            // Locale resolution (can redirect)
            $localeRedirect = $this->resolveLocaleAndUpdateContainer($request);
            if ($localeRedirect !== null) {
                return $this->finalizeSessionAndCookies($request, $localeRedirect);
            }

            $this->debugLog('H1', 'Application::handleRequest', 'route_discovery', [
                'path' => $this->getRoutingPath($request),
                'method' => $request->getMethod(),
                'duration_ms' => round((microtime(true) - $segmentStart) * 1000, 2),
            ], $runId);

            // Route matching and execution
            $response = $this->matchAndExecuteRoute($request);

            return $this->finalizeSessionAndCookies($request, $response);
        });
    }

    private function resolveTenancy(Request $request): ?Response
    {
        if ($this->tenancy === null || !$this->tenancy->isEnabled()) {
            return null;
        }
        return $this->tenancy->getHandler()->handle($request);
    }

    private function matchAndExecuteRoute(Request $request): Response
    {
        $routingPath = $this->getRoutingPath($request);
        $route = AttributeDiscovery::findRoute($routingPath, $request->getMethod());

        if ($route) {
            return $this->handleRoute($route, $request);
        }

        // Try alternate root path normalization ('/' vs '')
        if ($routingPath === '/' || $routingPath === '') {
            $altPath = $routingPath === '/' ? '' : '/';
            $route = AttributeDiscovery::findRoute($altPath, $request->getMethod());
            if ($route) {
                return $this->handleRoute($route, $request);
            }
        }

        return $this->getNotFoundResponse($request);
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

                throw new \RuntimeException('Unknown route type: ' . ($type ?? 'undefined'));
            } catch (\Throwable $e) {
                return $this->handleRouteException($e, $route, $request);
            }
        });
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

        if ($e instanceof \Semitexa\Core\Exception\NotFoundException) {
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

        if ($logger instanceof \Semitexa\Core\Log\LoggerInterface) {
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
        return $this->notFound();
    }

    private function notFound(): Response
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
        $handler = $this->sessionHandler;
        $handlerType = $handler instanceof RedisSessionHandler ? 'redis' : 'swoole_table';
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

        try {
            $session->save();
        } catch (\Throwable $e) {
            $this->sessionHandler = self::createSessionHandler();
            $this->logSessionPersistenceFailure($e, $request);
            return $response;
        }

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

    private function logSessionPersistenceFailure(\Throwable $e, Request $request): void
    {
        try {
            $logger = $this->container->get(\Semitexa\Core\Log\LoggerInterface::class);
            if (!$logger instanceof \Semitexa\Core\Log\LoggerInterface) {
                throw new \RuntimeException('Logger service has invalid type.');
            }

            $logger->error('Session persistence failed', [
                'path' => $request->getPath(),
                'method' => $request->getMethod(),
                'exception' => get_debug_type($e),
                'message' => $e->getMessage(),
            ]);
            return;
        } catch (\Throwable) {
            // Fall back to PHP error log when the logger is unavailable.
        }

        error_log('[Semitexa] Session persistence failed: ' . $e->getMessage());
    }

    /**
     * Resolve locale from request and set LocaleContextInterface in container.
     * Called after initSessionAndCookies so order is Tenant -> Locale.
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

        // 301 redirect: /{defaultLocale}/path -> /path (GET/HEAD only)
        if ($resolution !== null
            && $resolution->hadPathPrefix
            && $resolution->locale === $config->defaultLocale
            && $config->urlPrefixEnabled
            && $config->urlRedirectDefault
            && in_array($request->getMethod(), ['GET', 'HEAD'], true)
        ) {
            $target = $resolution->strippedPath ?: '/';
            $qs = $request->getQueryString();
            if ($qs !== '') {
                $target .= '?' . $qs;
            }
            return new Response('', HttpStatus::MovedPermanently->value, ['Location' => $target]);
        }

        // Store stripped path for routing
        if ($resolution !== null && $resolution->strippedPath !== null && $config->urlPrefixEnabled) {
            $this->localeStrippedPath = $resolution->strippedPath;
        }

        return null;
    }

    private function getRoutingPath(Request $request): string
    {
        if ($this->localeStrippedPath !== null) {
            return $this->localeStrippedPath;
        }

        return $request->getPath();
    }

    /**
     * Initialize request-scoped context interfaces (Tenant, Auth, Locale).
     * These are set into RequestScopedContainer for injection into handlers via #[InjectAsMutable].
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
