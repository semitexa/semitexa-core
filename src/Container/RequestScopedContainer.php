<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

use Semitexa\Core\Cookie\CookieJarInterface;
use Semitexa\Core\Request;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Auth\GuestAuthContext;
use Semitexa\Core\Locale\LocaleContextInterface;
use Semitexa\Core\Locale\DefaultLocaleContext;
use Psr\Container\ContainerInterface;

/**
 * Wrapper for request-scoped values (Session, Cookie, Request) and handler resolution.
 * Application sets Session/Cookie/Request per request; then RequestContext is applied to the
 * Semitexa container so mutable services get them injected after clone.
 */
class RequestScopedContainer
{
    private ContainerInterface $container;
    private array $requestScopedCache = [];

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Set a request-scoped instance (Session, CookieJar, Request).
     * When all three are set, RequestContext is passed to SemitexaContainer for mutable get().
     */
    public function set(string $id, object $instance): void
    {
        $this->requestScopedCache[$id] = $instance;
        $this->updateRequestContext();
    }

    private function updateRequestContext(): void
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
            $this->container->setRequestContext(new RequestContext($request, $session, $cookieJar));
        }
        
        if ($tenantContext instanceof TenantContextInterface) {
            $this->container->setTenantContext($tenantContext);
        }
        
        if ($authContext instanceof AuthContextInterface) {
            $this->container->setAuthContext($authContext);
        }
        
        if ($localeContext instanceof LocaleContextInterface) {
            $this->container->setLocaleContext($localeContext);
        }
    }

    /**
     * Get a service. Session/Cookie/Request must be set first by Application.
     * Handlers and other mutable services are resolved from SemitexaContainer (clone + RequestContext).
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
