<?php

declare(strict_types=1);

namespace Semitexa\Core\Lifecycle;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Auth\GuestAuthContext;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Cookie\CookieJar;
use Semitexa\Core\Cookie\CookieJarInterface;
use Semitexa\Core\Csrf\CsrfToken;
use Semitexa\Core\Environment;
use Semitexa\Core\Locale\DefaultLocaleContext;
use Semitexa\Core\Locale\LocaleContextInterface;
use Semitexa\Core\Log\FallbackErrorLogger;
use Semitexa\Core\Request;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\Redis\RedisConnectionPool;
use Semitexa\Core\Session\RedisSessionHandler;
use Semitexa\Core\Session\Session;
use Semitexa\Core\Session\SessionHandlerInterface;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Session\SwooleTableSessionHandler;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Core\Tenant\TenancyBootstrapperInterface;

/**
 * @internal Initializes session, cookies, and context interfaces; finalizes session after response.
 */
final class SessionPhase
{
    /**
     * Process-wide on purpose: this is a deployment fact, not request state,
     * so it must NOT be a CoroutineLocal. One warning per worker is the
     * whole design.
     */
    private static bool $refusedHttpsWarned = false;

    private SessionHandlerInterface $sessionHandler;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly RequestScopedContainer $requestScopedContainer,
        private readonly TenantContextStoreInterface $tenantContextStore,
        private readonly ?TenancyBootstrapperInterface $tenancy,
    ) {
        $this->sessionHandler = $this->createSessionHandler();
    }

    public function execute(RequestLifecycleContext $context): void
    {
        $request = $context->request;

        $cookieName = Environment::getEnvValue('SESSION_COOKIE_NAME') ?? 'semitexa_session';
        $sessionId = $request->getCookie($cookieName, '');
        $fromCookie = $sessionId !== '' && strlen($sessionId) === 32;
        if (!$fromCookie) {
            $sessionId = bin2hex(random_bytes(16));
        }
        $sessionLifetime = (int) (Environment::getEnvValue('SESSION_LIFETIME') ?? '3600');
        $session = new Session($sessionId, $this->sessionHandler, $cookieName, $sessionLifetime);

        // Ensure a CSRF token exists on this session. Generated once, then stable
        // for the session lifetime. The matching XSRF-TOKEN cookie is emitted in
        // finalize() so browser JS can copy it into the X-CSRF-Token header.
        /** @var CsrfToken $csrf */
        $csrf = $session->getPayload(CsrfToken::class);
        if ($csrf->getValue() === '') {
            $csrf = CsrfToken::generate();
            $session->setPayload($csrf);
        }

        $this->requestScopedContainer->set(SessionInterface::class, $session);
        $this->requestScopedContainer->set(CookieJarInterface::class, new CookieJar($request));
        $this->requestScopedContainer->set(Request::class, $request);

        $this->initContextInterfaces();
    }

    public function finalize(RequestLifecycleContext $context, HttpResponse $response): HttpResponse
    {
        $request = $context->request;

        if (!$this->requestScopedContainer->has(SessionInterface::class)
            || !$this->requestScopedContainer->has(CookieJarInterface::class)
        ) {
            return $response;
        }
        $session = $this->requestScopedContainer->get(SessionInterface::class);
        $cookieJar = $this->requestScopedContainer->get(CookieJarInterface::class);

        if (!$session instanceof Session || !$cookieJar instanceof CookieJarInterface) {
            return $response;
        }

        $sessionPersisted = false;

        try {
            $session->save();
            $sessionPersisted = true;
        } catch (\Throwable $e) {
            $this->logSessionPersistenceFailure($e, $request);
            try {
                $this->sessionHandler = $this->createSessionHandler();
                $session->setHandler($this->sessionHandler);
                $session->save();
                $sessionPersisted = true;
            } catch (\Throwable $retryError) {
                $this->logSessionPersistenceFailure($retryError, $request);
            }
        }

        if ($sessionPersisted) {
            $cookieName = $session->getCookieName();
            $sessionLifetime = (int) (Environment::getEnvValue('SESSION_LIFETIME') ?? '3600');
            $isHttps = $request->getScheme() === 'https';

            // Optional parent-domain scope (e.g. ".example.com") so ONE session is
            // shared across sibling hosts of the same site — a public apex and an
            // authenticated subdomain, say. Unset (the default) keeps the cookie
            // host-only, so each host has its own isolated session. Applied to
            // both the session and the XSRF cookie so the CSRF double-submit
            // stays consistent across those hosts too.
            $cookieDomain = (string) (Environment::getEnvValue('SESSION_COOKIE_DOMAIN') ?? '');
            $secure = $this->cookieSecureFlag($request, $isHttps);
            $baseOptions = [
                'path' => '/',
                'secure' => $secure,
                'sameSite' => 'lax',
                'maxAge' => $sessionLifetime,
            ];
            if ($cookieDomain !== '') {
                $baseOptions['domain'] = $cookieDomain;
            }

            $cookieJar->set($cookieName, $session->getSessionIdForCookie(), ['httpOnly' => true] + $baseOptions);

            // XSRF-TOKEN cookie for the double-submit CSRF flow. Must NOT be
            // HttpOnly — browser JS has to read it to populate X-CSRF-Token.
            // The session-stored CsrfToken remains the authoritative value; the
            // cookie is only a transport so AJAX clients can echo it back.
            /** @var CsrfToken $csrf */
            $csrf = $session->getPayload(CsrfToken::class);
            if ($csrf->getValue() !== '') {
                $cookieJar->set('XSRF-TOKEN', $csrf->getValue(), ['httpOnly' => false] + $baseOptions);
            }
        }

        $lines = $cookieJar->getSetCookieLines();
        if ($lines !== []) {
            /** @var array<int|string, string> $lines */
            $response = $response->withHeaders(['Set-Cookie' => $lines]);
        }

        return $response;
    }

    private function createSessionHandler(): SessionHandlerInterface
    {
        $redisHost = Environment::getEnvValue('REDIS_HOST');
        if ($redisHost !== null && $redisHost !== '') {
            if ($this->container->has(RedisConnectionPool::class)) {
                $pool = $this->container->get(RedisConnectionPool::class);
                if ($pool instanceof RedisConnectionPool) {
                    return new RedisSessionHandler($pool);
                }
            }
            throw new \RuntimeException(
                'Redis is configured (REDIS_HOST is set) but no RedisConnectionPool '
                . 'was registered during container bootstrap. Ensure Redis is properly '
                . 'configured in the application setup.'
            );
        }
        return new SwooleTableSessionHandler();
    }

    /**
     * Whether the session and XSRF cookies get `Secure`.
     *
     * SESSION_COOKIE_SECURE overrides the scheme:
     *   auto (default) — follow the request scheme, as before;
     *   always         — an HTTPS-only deployment says so and stops depending
     *                    on scheme detection working;
     *   never          — plain HTTP on purpose, e.g. a local box behind a VPN.
     *
     * The warning is the point of this method. Dropping Secure because an
     * untrusted peer claimed https is a DOWNGRADE, and until now it happened
     * without a word: core#102 already recorded that the project's own
     * containerised topology puts the proxy off loopback, TRUSTED_PROXIES was
     * added as the remedy, it reached no shipped .env, and semitexa.com was
     * still serving Secure-less cookies over HTTPS months later. Nothing was
     * broken enough to notice, which is exactly why it needs to say something.
     */
    private function cookieSecureFlag(Request $request, bool $isHttps): bool
    {
        $configured = strtolower(trim((string) (Environment::getEnvValue('SESSION_COOKIE_SECURE') ?? 'auto')));

        $secure = match ($configured) {
            'always', 'true', '1', 'on' => true,
            'never', 'false', '0', 'off' => false,
            default => $isHttps,
        };

        if (!$secure && $request->refusedForwardedProto() === 'https') {
            $this->warnAboutRefusedHttps($request);
        }

        return $secure;
    }

    /**
     * Once per worker. A misconfigured proxy is true for every request, so one
     * line per boot says it; one line per request buries it.
     */
    private function warnAboutRefusedHttps(Request $request): void
    {
        if (self::$refusedHttpsWarned) {
            return;
        }
        self::$refusedHttpsWarned = true;

        $context = [
            'remote_addr' => $request->getServer('remote_addr'),
            'remedy' => 'Add that address to TRUSTED_PROXIES, or set SESSION_COOKIE_SECURE=always.',
        ];
        $message = 'A proxy said X-Forwarded-Proto: https and was not trusted, so the session '
            . 'and XSRF cookies are being sent WITHOUT the Secure flag.';

        $logger = $this->container->has(\Semitexa\Core\Log\LoggerInterface::class)
            ? $this->container->get(\Semitexa\Core\Log\LoggerInterface::class)
            : null;

        if ($logger instanceof \Semitexa\Core\Log\LoggerInterface) {
            $logger->warning($message, $context);
        } else {
            FallbackErrorLogger::log($message, $context);
        }
    }

    private function logSessionPersistenceFailure(\Throwable $e, Request $request): void
    {
        $logger = $this->container->has(\Semitexa\Core\Log\LoggerInterface::class)
            ? $this->container->get(\Semitexa\Core\Log\LoggerInterface::class)
            : null;

        if ($logger instanceof \Semitexa\Core\Log\LoggerInterface) {
            $logger->error('Session persistence failed', [
                'path' => $request->getPath(),
                'method' => $request->getMethod(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        } else {
            FallbackErrorLogger::log('Session persistence failed', [
                'path' => $request->getPath(),
                'method' => $request->getMethod(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Initialize request-scoped context interfaces (Tenant, Auth, Locale).
     */
    private function initContextInterfaces(): void
    {
        if ($this->tenancy === null || !$this->tenancy->isEnabled()) {
            $this->tenantContextStore->clear();
        }

        $tenantContext = $this->resolveTenantContext();
        $this->requestScopedContainer->set(TenantContextInterface::class, $tenantContext);

        // AuthContextInterface is supplied by semitexa-auth through a SatisfiesServiceContract
        // binding. When the auth package is not installed, fall back to the Core-owned guest
        // context so downstream consumers always receive a non-null auth context.
        $authContext = $this->container->has(AuthContextInterface::class)
            ? $this->container->get(AuthContextInterface::class)
            : $this->resolveLegacyAuthContext();
        /** @var AuthContextInterface $authContext */
        $this->requestScopedContainer->set(AuthContextInterface::class, $authContext);

        $localeContext = DefaultLocaleContext::getInstance();
        $this->requestScopedContainer->set(LocaleContextInterface::class, $localeContext);
    }

    private function resolveTenantContext(): TenantContextInterface
    {
        $tenantContext = $this->tenantContextStore->tryGet();
        if ($tenantContext instanceof TenantContextInterface) {
            return $tenantContext;
        }

        if ($this->tenancy !== null && $this->tenancy->isEnabled()) {
            $legacyStoreClass = 'Semitexa\\Tenancy\\Context\\CoroutineContextStore';
            try {
                $legacyTenantContext = $legacyStoreClass::get();
                if ($legacyTenantContext instanceof TenantContextInterface) {
                    $this->tenantContextStore->set($legacyTenantContext);

                    return $legacyTenantContext;
                }
            } catch (\Throwable) {
                // Mixed-version fallback: ignore missing legacy store and keep the new store value.
            }
        }

        return $this->tenantContextStore->get();
    }

    private function resolveLegacyAuthContext(): AuthContextInterface
    {
        if ($this->container->has(\Semitexa\Auth\Context\AuthManager::class)) {
            $legacyAuthContext = $this->container->get(\Semitexa\Auth\Context\AuthManager::class);
            if ($legacyAuthContext instanceof AuthContextInterface) {
                return $legacyAuthContext;
            }
        }

        return GuestAuthContext::getInstance();
    }
}
