<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Cookie\CookieJarInterface;
use Semitexa\Core\Locale\LocaleContextInterface;
use Semitexa\Core\Request;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Tenant\TenantContextInterface;
use Psr\Container\ContainerInterface;

/**
 * Wrapper for request-scoped values (Session, Cookie, Request) and handler resolution.
 * Application sets Session/Cookie/Request per request; then ExecutionContext is applied to the
 * Semitexa container so execution-scoped services get them injected after clone.
 */
class RequestScopedContainer implements ContainerInterface
{
    private ContainerInterface $container;
    private array $requestScopedCache = [];

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Set a request-scoped instance (Session, CookieJar, Request, context interfaces).
     * When all three HTTP values are set, ExecutionContext is passed to SemitexaContainer.
     */
    public function set(string $id, object $instance): void
    {
        $this->requestScopedCache[$id] = $instance;
        $this->updateExecutionContext();
    }

    private function updateExecutionContext(): void
    {
        if (!$this->container instanceof SemitexaContainer) {
            return;
        }
        $request = $this->requestScopedCache[Request::class] ?? null;
        $session = $this->requestScopedCache[SessionInterface::class] ?? null;
        $cookieJar = $this->requestScopedCache[CookieJarInterface::class] ?? null;
        $tenantContext = $this->requestScopedCache[TenantContextInterface::class] ?? null;
        $authContext = $this->requestScopedCache[AuthContextInterface::class] ?? null;
        $localeContext = $this->requestScopedCache[LocaleContextInterface::class] ?? null;

        if ($request instanceof Request && $session instanceof SessionInterface && $cookieJar instanceof CookieJarInterface) {
            $this->container->setExecutionContext(new ExecutionContext(
                request: $request,
                session: $session,
                cookieJar: $cookieJar,
                tenantContext: $tenantContext instanceof TenantContextInterface ? $tenantContext : null,
                authContext: $authContext instanceof AuthContextInterface ? $authContext : null,
                localeContext: $localeContext instanceof LocaleContextInterface ? $localeContext : null,
            ));
        }
    }

    public function has(string $id): bool
    {
        if (isset($this->requestScopedCache[$id])) {
            return true;
        }
        if (
            $id === SessionInterface::class
            || $id === CookieJarInterface::class
            || $id === Request::class
        ) {
            return false;
        }
        return $this->container->has($id);
    }

    /**
     * Get a service. Session/Cookie/Request must be set first by Application.
     * Handlers and other execution-scoped services are resolved from SemitexaContainer (clone + ExecutionContext).
     */
    public function get(string $id): mixed
    {
        if (isset($this->requestScopedCache[$id])) {
            return $this->requestScopedCache[$id];
        }
        if (
            $id === SessionInterface::class
            || $id === CookieJarInterface::class
            || $id === Request::class
        ) {
            throw new \RuntimeException("{$id} is not set. Ensure Application initializes session, cookies, and request at request start.");
        }
        return $this->container->get($id);
    }

    /**
     * Reset request-scoped cache (call after each request in Swoole).
     */
    public function reset(): void
    {
        $this->requestScopedCache = [];
    }
}
