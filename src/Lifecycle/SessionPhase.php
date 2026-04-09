<?php

declare(strict_types=1);

namespace Semitexa\Core\Lifecycle;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Cookie\CookieJar;
use Semitexa\Core\Cookie\CookieJarInterface;
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
use Semitexa\Core\Tenant\DefaultTenantContext;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Tenancy\Context\CoroutineContextStore;
use Semitexa\Tenancy\Context\TenantContext as TenancyTenantContext;
use Semitexa\Tenancy\TenancyBootstrapper;

/**
 * @internal Initializes session, cookies, and context interfaces; finalizes session after response.
 */
final class SessionPhase
{
    private SessionHandlerInterface $sessionHandler;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly RequestScopedContainer $requestScopedContainer,
        private readonly ?TenancyBootstrapper $tenancy,
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
            $cookieJar->set($cookieName, $session->getSessionIdForCookie(), [
                'path' => '/',
                'httpOnly' => true,
                'sameSite' => 'lax',
                'maxAge' => $sessionLifetime,
            ]);
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
        if ($this->tenancy !== null && $this->tenancy->isEnabled()) {
            $resolved = CoroutineContextStore::get();
            if ($resolved !== null) {
                TenancyTenantContext::set($resolved);
                $this->requestScopedContainer->set(TenantContextInterface::class, $resolved);
            } else {
                TenancyTenantContext::clear();
                $this->requestScopedContainer->set(TenantContextInterface::class, DefaultTenantContext::getInstance());
            }
        } else {
            TenancyTenantContext::clear();
            $this->requestScopedContainer->set(TenantContextInterface::class, DefaultTenantContext::getInstance());
        }

        $authContext = \Semitexa\Auth\Context\AuthManager::getInstance();
        $this->requestScopedContainer->set(AuthContextInterface::class, $authContext);

        $localeContext = DefaultLocaleContext::getInstance();
        $this->requestScopedContainer->set(LocaleContextInterface::class, $localeContext);
    }
}
