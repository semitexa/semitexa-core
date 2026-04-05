<?php

declare(strict_types=1);

namespace Semitexa\Core;

use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Container\SemitexaContainer;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Core\Lifecycle\LocalePhase;
use Semitexa\Core\Lifecycle\LifecycleComponentRegistry;
use Semitexa\Core\Lifecycle\RequestLifecycleContext;
use Semitexa\Core\Lifecycle\RoutePhase;
use Semitexa\Core\Lifecycle\SessionPhase;
use Semitexa\Core\Lifecycle\TenancyPhase;
use Semitexa\Core\Support\CoroutineLocal;

/**
 * Minimal Semitexa Application
 */
class Application
{
    /** @deprecated Use RoutePhase::ROUTE_NAME_404 instead */
    public const ROUTE_NAME_404 = 'error.404';
    /** @deprecated Use RoutePhase::ROUTE_NAME_500 instead */
    public const ROUTE_NAME_500 = 'error.500';

    public Environment $environment {
        get {
            return $this->environment;
        }
    }

    private SemitexaContainer $container {
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

    public function __construct(?SemitexaContainer $container = null)
    {
        $this->container = $container ?? ContainerFactory::get();
        $this->requestScopedContainer = ContainerFactory::createRequestScoped();
        /** @var Environment $environment */
        $environment = $this->container->get(Environment::class);
        $this->environment = $environment;

        /** @var LifecycleComponentRegistry $componentRegistry */
        $componentRegistry = $this->container->get(LifecycleComponentRegistry::class);

        $events = $this->container->has(EventDispatcherInterface::class)
            ? $this->container->get(EventDispatcherInterface::class)
            : null;

        /** @var ClassDiscovery|null $classDiscovery */
        $classDiscovery = $this->container->getOrNull(ClassDiscovery::class);

        $tenancy = $componentRegistry->createTenancyBootstrapper(
            classDiscovery: $classDiscovery,
            events: $events instanceof EventDispatcherInterface ? $events : null,
        );

        $authBootstrapper = $componentRegistry->createAuthBootstrapper(
            container: $this->container,
            requestScopedContainer: $this->requestScopedContainer,
            classDiscovery: $classDiscovery,
            events: $events instanceof EventDispatcherInterface ? $events : null,
        );

        $localeBootstrapper = $componentRegistry->createLocaleBootstrapper(
            events: $events instanceof EventDispatcherInterface ? $events : null,
        );

        $this->tenancyPhase = new TenancyPhase($tenancy);
        $this->sessionPhase = new SessionPhase($this->container, $this->requestScopedContainer, $tenancy);
        $this->localePhase = new LocalePhase($this->requestScopedContainer, $localeBootstrapper);
        $this->routePhase = new RoutePhase($this->container, $this->requestScopedContainer, $authBootstrapper, $this->environment);
    }

    public function handleRequest(Request $request): HttpResponse
    {
        CoroutineLocal::beginRequest();

        try {
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
        } finally {
            CoroutineLocal::endRequest();
        }
    }

    /**
     * @param array{name?: string}|null $currentRoute
     */
    public function renderErrorThrowable(\Throwable $throwable, Request $request, ?array $currentRoute = null): ?HttpResponse
    {
        return $this->routePhase->renderErrorThrowable($throwable, $request, $currentRoute);
    }

    /**
     * @param array{name?: string}|null $currentRoute
     */
    public function renderErrorStatus(int $statusCode, Request $request, ?array $currentRoute = null): ?HttpResponse
    {
        return $this->routePhase->renderErrorStatus($statusCode, $request, $currentRoute);
    }
}
