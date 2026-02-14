<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

use DI\Container;
use Semitexa\Core\Cookie\CookieJarInterface;
use Semitexa\Core\Request;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Tenancy\TenantContext;

/**
 * Wrapper for request-scoped services in Swoole
 * 
 * This ensures that services that should be request-scoped
 * are created fresh for each request, preventing data leakage.
 * Also stores tenant context for request-level isolation.
 */
class RequestScopedContainer
{
    private Container $container;
    private array $requestScopedCache = [];
    private ?TenantContext $tenantContext = null;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Set a request-scoped instance (e.g. Session, CookieJar created by Application from Request).
     */
    public function set(string $id, object $instance): void
    {
        $this->requestScopedCache[$id] = $instance;
    }

    /**
     * Get a service - creates new instance for request-scoped services
     * 
     * For handlers with property injection (#[Inject]), PHP-DI needs autowire() definition
     * and we use make() + injectOn() which respects autowiring and property injection
     */
    public function get(string $id): mixed
    {
        if (isset($this->requestScopedCache[$id])) {
            return $this->requestScopedCache[$id];
        }

        // Session, Cookie, and HTTP Request are set per-request by Application; do not auto-create
        if (
            $id === \Semitexa\Core\Session\SessionInterface::class
            || $id === \Semitexa\Core\Cookie\CookieJarInterface::class
            || $id === \Semitexa\Core\Request::class
        ) {
            throw new \RuntimeException("{$id} is not set. Ensure Application initializes session, cookies, and request at request start.");
        }

        // Check if this is a request-scoped service
        if ($this->isRequestScoped($id)) {
            // For request-scoped services, check if we have a definition
            // If we have autowire() definition, use get() which properly handles property injection
            // But we need to ensure it's a new instance, not singleton
            // Ensure handlers get the same Session/CookieJar/Request as Application::finalizeSessionAndCookies uses.
            $makeParams = $this->getRequestScopedConstructorOverrides($id);
            $instance = $this->container->make($id, $makeParams);
            
            // CRITICAL: Always call injectOn() for request-scoped services
            // make() doesn't automatically inject properties even with autowire() in some contexts
            // injectOn() explicitly performs property injection based on #[Inject] attributes
            try {
                $this->container->injectOn($instance);
            } catch (\Throwable $e) {
                // Log detailed error for debugging
                error_log("RequestScopedContainer: injectOn() failed for {$id}");
                error_log("Error: " . $e->getMessage());
                error_log("File: " . $e->getFile() . ":" . $e->getLine());
                
                // Verify properties are actually null
                $reflection = new \ReflectionClass($instance);
                foreach ($reflection->getProperties() as $property) {
                    $attributes = $property->getAttributes(\DI\Attribute\Inject::class);
                    if (!empty($attributes)) {
                        $property->setAccessible(true);
                        $value = $property->getValue($instance);
                        if ($value === null) {
                            error_log("Property {$property->getName()} is NULL after injectOn()");
                        }
                    }
                }
                
                // Re-throw to see the actual error
                throw new \RuntimeException("Failed to inject dependencies into {$id}: " . $e->getMessage(), 0, $e);
            }
            
            // Double-check that properties are injected
            $reflection = new \ReflectionClass($instance);
            foreach ($reflection->getProperties() as $property) {
                $attributes = $property->getAttributes(\DI\Attribute\Inject::class);
                if (!empty($attributes)) {
                    $property->setAccessible(true);
                    $value = $property->getValue($instance);
                    if ($value === null) {
                        error_log("WARNING: Property {$property->getName()} in {$id} is still NULL after injectOn()");
                        // Try to manually resolve and inject
                        $propertyType = $property->getType();
                        if ($propertyType && !$propertyType->isBuiltin()) {
                            $typeName = $propertyType->getName();
                            try {
                                $resolved = $this->container->get($typeName);
                                $property->setValue($instance, $resolved);
                                error_log("Manually injected {$property->getName()} with {$typeName}");
                            } catch (\Throwable $e2) {
                                error_log("Failed to manually inject {$property->getName()}: " . $e2->getMessage());
                            }
                        }
                    }
                }
            }
            
            return $instance;
        }

        // Use singleton for infrastructure services
        return $this->container->get($id);
    }

    /**
     * Build constructor parameter overrides so that Session, CookieJar, and Request
     * always come from the request-scoped cache (same instances as in finalizeSessionAndCookies).
     */
    private function getRequestScopedConstructorOverrides(string $id): array
    {
        $params = [];
        try {
            $reflection = new \ReflectionClass($id);
            $constructor = $reflection->getConstructor();
            if ($constructor === null) {
                return $params;
            }
            foreach ($constructor->getParameters() as $param) {
                $type = $param->getType();
                if (!$type || $type->isBuiltin()) {
                    continue;
                }
                $name = $type->getName();
                if ($name === SessionInterface::class && isset($this->requestScopedCache[SessionInterface::class])) {
                    $params[$param->getName()] = $this->requestScopedCache[SessionInterface::class];
                } elseif ($name === CookieJarInterface::class && isset($this->requestScopedCache[CookieJarInterface::class])) {
                    $params[$param->getName()] = $this->requestScopedCache[CookieJarInterface::class];
                } elseif ($name === Request::class && isset($this->requestScopedCache[Request::class])) {
                    $params[$param->getName()] = $this->requestScopedCache[Request::class];
                }
            }
        } catch (\Throwable) {
            // If reflection fails, return no overrides (container will resolve as usual)
        }
        return $params;
    }

    /**
     * Check if service should be request-scoped
     */
    private function isRequestScoped(string $id): bool
    {
        // Services that handle request data should be request-scoped
        $requestScopedPatterns = [
            'Handler',
            'Service', // Most services should be request-scoped
            'Repository', // If they cache data
        ];

        foreach ($requestScopedPatterns as $pattern) {
            if (str_contains($id, $pattern)) {
                return true;
            }
        }

        // Infrastructure services are singletons
        $singletonPatterns = [
            'Environment',
            'Registry',
            'Factory',
            'Pool', // Connection pools
        ];

        foreach ($singletonPatterns as $pattern) {
            if (str_contains($id, $pattern)) {
                return false;
            }
        }

        // Default: request-scoped for safety
        return true;
    }

    /**
     * Set tenant context for current request
     */
    public function setTenantContext(TenantContext $tenantContext): void
    {
        $this->tenantContext = $tenantContext;
    }

    /**
     * Get tenant context for current request
     */
    public function getTenantContext(): ?TenantContext
    {
        return $this->tenantContext;
    }

    /**
     * Reset request-scoped cache and tenant context (call after each request)
     * 
     * CRITICAL: This must be called after each request in Swoole to prevent
     * data leakage between requests/tenants.
     */
    public function reset(): void
    {
        $this->requestScopedCache = [];
        $this->tenantContext = null; // Clear tenant context to prevent leakage
    }
}

