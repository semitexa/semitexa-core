<?php

declare(strict_types=1);

namespace Semitexa\Core;

use Semitexa\Auth\AuthBootstrapper;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Core\Lifecycle\LocalePhase;
use Semitexa\Core\Lifecycle\RequestLifecycleContext;
use Semitexa\Core\Lifecycle\RoutePhase;
use Semitexa\Core\Lifecycle\SessionPhase;
use Semitexa\Core\Lifecycle\TenancyPhase;
use Psr\Container\ContainerInterface;
use Semitexa\Locale\LocaleBootstrapper;
use Semitexa\Tenancy\TenancyBootstrapper;


/**
 * Minimal Semitexa Application
 */
class Application
{
    /** @deprecated Use RoutePhase::ROUTE_NAME_404 instead */
    public const ROUTE_NAME_404 = 'error.404';

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

    private TenancyPhase $tenancyPhase;
    private SessionPhase $sessionPhase;
    private LocalePhase $localePhase;
    private RoutePhase $routePhase;

    public function __construct(?ContainerInterface $container = null)
    {
        $this->container = $container ?? \Semitexa\Core\Container\ContainerFactory::get();
        $this->requestScopedContainer = \Semitexa\Core\Container\ContainerFactory::createRequestScoped();
        $this->environment = $this->container->get(Environment::class);

        $events = $this->container->has(EventDispatcherInterface::class)
            ? $this->container->get(EventDispatcherInterface::class)
            : null;

        $classDiscovery = $this->container->has(ClassDiscovery::class)
            ? $this->container->get(ClassDiscovery::class)
            : null;

        $tenancy = new TenancyBootstrapper(
            classDiscovery: $classDiscovery instanceof ClassDiscovery ? $classDiscovery : null,
            events: $events instanceof EventDispatcherInterface ? $events : null,
        );

        $authBootstrapper = null;
        if (class_exists(AuthBootstrapper::class)) {
            $authBootstrapper = new AuthBootstrapper(
                container: $this->container,
                classDiscovery: $classDiscovery instanceof ClassDiscovery ? $classDiscovery : new ClassDiscovery(),
                events: $events instanceof EventDispatcherInterface ? $events : null,
                requestScopedContainer: $this->requestScopedContainer,
            );
        }

        $localeBootstrapper = null;
        if (class_exists(LocaleBootstrapper::class)) {
            $localeManager = new \Semitexa\Locale\Context\LocaleManager();
            $localeBootstrapper = new LocaleBootstrapper($localeManager, events: $events);
        }

        $this->tenancyPhase = new TenancyPhase($tenancy);
        $this->sessionPhase = new SessionPhase($this->container, $this->requestScopedContainer, $tenancy);
        $this->localePhase = new LocalePhase($this->requestScopedContainer, $localeBootstrapper);
        $this->routePhase = new RoutePhase($this->container, $this->requestScopedContainer, $authBootstrapper, $this->environment);
    }

    public function handleRequest(Request $request): HttpResponse
    {
        $context = new RequestLifecycleContext($request);

        // Tenant resolution (can short-circuit)
        $this->tenancyPhase->execute($context);
        if ($context->hasEarlyResponse()) {
            return $context->getEarlyResponse();
        }

        // Session and cookies
        $this->sessionPhase->execute($context);

        // Locale resolution (can redirect)
        $this->localePhase->execute($context);
        if ($context->hasEarlyResponse()) {
            return $this->sessionPhase->finalize($context, $context->getEarlyResponse());
        }

        // Route matching and execution
        $response = $this->routePhase->execute($context);

        return $this->sessionPhase->finalize($context, $response);
    }
}
