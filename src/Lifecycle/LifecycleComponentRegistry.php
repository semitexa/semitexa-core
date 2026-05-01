<?php

declare(strict_types=1);

namespace Semitexa\Core\Lifecycle;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Auth\AuthBootstrapperFactoryInterface;
use Semitexa\Core\Auth\AuthBootstrapperInterface;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Core\Tenant\TenancyBootstrapperFactoryInterface;
use Semitexa\Core\Tenant\TenancyBootstrapperInterface;
use Semitexa\Core\Request;
use Semitexa\Core\HttpResponse;
use Semitexa\Locale\Context\LocaleManager;
use Semitexa\Locale\Application\Service\LocaleBootstrapper;

/**
 * Centralizes detection and creation of optional lifecycle bootstrappers.
 *
 * Replaces scattered class_exists() checks in Application with a single
 * source of truth for which optional packages are available and how to
 * construct their bootstrappers.
 *
 * Registered as a readonly service during RegistryBuildPhase.
 *
 * @internal Used by Application to construct lifecycle phase dependencies.
 */
final class LifecycleComponentRegistry
{
    private bool $tenancyAvailable;
    private bool $authAvailable;
    private bool $localeAvailable;

    public function __construct(private readonly ModuleRegistry $moduleRegistry)
    {
        $this->tenancyAvailable = $this->moduleRegistry->isActive('semitexa-tenancy');
        $this->authAvailable = $this->moduleRegistry->isActive('semitexa-auth');
        $this->localeAvailable = $this->moduleRegistry->isActive('semitexa-locale');
    }

    public function isTenancyAvailable(): bool
    {
        return $this->tenancyAvailable;
    }

    public function isAuthAvailable(): bool
    {
        return $this->authAvailable;
    }

    public function isLocaleAvailable(): bool
    {
        return $this->localeAvailable;
    }

    /**
     * Create a TenancyBootstrapper instance.
     * Returns null if the tenancy package is not available.
     */
    public function createTenancyBootstrapper(
        ContainerInterface $container,
        ?ClassDiscovery $classDiscovery = null,
        ?EventDispatcherInterface $events = null,
    ): ?TenancyBootstrapperInterface {
        if (!$this->tenancyAvailable) {
            return null;
        }

        if (!$container->has(TenancyBootstrapperFactoryInterface::class)) {
            $bootstrapperClass = 'Semitexa\\Tenancy\\TenancyBootstrapper';
            if (!class_exists($bootstrapperClass)) {
                return null;
            }

            $namedArgs = [
                'classDiscovery' => $classDiscovery,
                'events' => $events,
            ];
            if ($container->has(TenantContextStoreInterface::class)) {
                $namedArgs['tenantContextStore'] = $container->get(TenantContextStoreInterface::class);
            }

            $bootstrapper = $this->instantiateLifecycleComponent($bootstrapperClass, $namedArgs);

            return $bootstrapper !== null ? $this->adaptTenancyBootstrapper($bootstrapper) : null;
        }

        /** @var TenancyBootstrapperFactoryInterface $factory */
        $factory = $container->get(TenancyBootstrapperFactoryInterface::class);

        return $factory->create($container);
    }

    /**
     * Create an AuthBootstrapperInterface via the factory binding when present,
     * otherwise fall back to a compatible Semitexa\Auth\Application\Service\AuthBootstrapper class.
     * Returns null if the auth package is not active or no compatible
     * bootstrapper can be constructed.
     */
    public function createAuthBootstrapper(
        ContainerInterface $container,
        RequestScopedContainer $requestScopedContainer,
    ): ?AuthBootstrapperInterface {
        if (!$this->authAvailable) {
            return null;
        }

        if (!$container->has(AuthBootstrapperFactoryInterface::class)) {
            $bootstrapperClass = 'Semitexa\\Auth\\AuthBootstrapper';
            if (!class_exists($bootstrapperClass)) {
                return null;
            }

            $classDiscovery = $container->has(ClassDiscovery::class)
                ? $container->get(ClassDiscovery::class)
                : null;
            $authContext = $container->has(AuthContextInterface::class)
                ? $container->get(AuthContextInterface::class)
                : null;
            $logger = $container->has(LoggerInterface::class)
                ? $container->get(\Semitexa\Core\Log\LoggerInterface::class)
                : null;
            $events = $container->has(EventDispatcherInterface::class)
                ? $container->get(EventDispatcherInterface::class)
                : null;

            $bootstrapper = $this->instantiateLifecycleComponent($bootstrapperClass, [
                'container' => $container,
                'requestScopedContainer' => $requestScopedContainer,
                'classDiscovery' => $classDiscovery,
                'authContext' => $authContext,
                'events' => $events,
                'logger' => $logger,
            ]);

            return $bootstrapper !== null ? $this->adaptAuthBootstrapper($bootstrapper) : null;
        }

        /** @var AuthBootstrapperFactoryInterface $factory */
        $factory = $container->get(AuthBootstrapperFactoryInterface::class);

        return $factory->create($container, $requestScopedContainer);
    }

    /**
     * Create a LocaleBootstrapper instance.
     * Returns null if the locale package is not available.
     */
    public function createLocaleBootstrapper(
        ?EventDispatcherInterface $events = null,
    ): ?LocaleBootstrapper {
        if (!$this->localeAvailable) {
            return null;
        }
        return new LocaleBootstrapper(
            new LocaleManager(),
            events: $events,
        );
    }

    /**
     * Instantiate a lifecycle component by matching known named dependencies to
     * the target constructor's declared parameter names.
     *
     * This keeps Core compatible with pre-factory bootstrapper builds whose
     * constructor signatures differ from the current package versions.
     *
     * @param class-string $className
     * @param array<string, mixed> $namedArgs
     */
    private function instantiateLifecycleComponent(string $className, array $namedArgs): ?object
    {
        $reflection = new \ReflectionClass($className);
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $args = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $namedArgs)) {
                $value = $namedArgs[$name];
                if ($value === null && !$parameter->allowsNull()) {
                    if ($parameter->isDefaultValueAvailable()) {
                        $args[] = $parameter->getDefaultValue();
                        continue;
                    }

                    return null;
                }

                $args[] = $value;
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $args[] = $parameter->getDefaultValue();
                continue;
            }

            if ($parameter->allowsNull()) {
                $args[] = null;
                continue;
            }

            return null;
        }

        return $reflection->newInstanceArgs($args);
    }

    private function adaptTenancyBootstrapper(object $bootstrapper): ?TenancyBootstrapperInterface
    {
        if ($bootstrapper instanceof TenancyBootstrapperInterface) {
            return $bootstrapper;
        }

        if (!is_callable([$bootstrapper, 'isEnabled'])) {
            return null;
        }

        $isEnabled = \Closure::fromCallable([$bootstrapper, 'isEnabled']);
        $resolve = $this->legacyTenancyResolveClosure($bootstrapper);
        if ($resolve === null) {
            return null;
        }

        return new class($isEnabled, $resolve) implements TenancyBootstrapperInterface
        {
            public function __construct(
                private readonly \Closure $isEnabled,
                private readonly \Closure $resolve,
            )
            {
            }

            public function isEnabled(): bool
            {
                return (bool) ($this->isEnabled)();
            }

            public function resolve(Request $request): ?HttpResponse
            {
                $response = ($this->resolve)($request);

                return $response instanceof HttpResponse ? $response : null;
            }
        };
    }

    private function legacyTenancyResolveClosure(object $bootstrapper): ?\Closure
    {
        if (is_callable([$bootstrapper, 'resolve'])) {
            return \Closure::fromCallable([$bootstrapper, 'resolve']);
        }

        if (!is_callable([$bootstrapper, 'getHandler'])) {
            return null;
        }

        $getHandler = \Closure::fromCallable([$bootstrapper, 'getHandler']);

        return static function (Request $request) use ($getHandler): ?HttpResponse {
            $handler = $getHandler();
            if (!is_object($handler) || !is_callable([$handler, 'handle'])) {
                return null;
            }

            $response = $handler->handle($request);

            return $response instanceof HttpResponse ? $response : null;
        };
    }

    private function adaptAuthBootstrapper(object $bootstrapper): ?AuthBootstrapperInterface
    {
        if ($bootstrapper instanceof AuthBootstrapperInterface) {
            return $bootstrapper;
        }

        if (!is_callable([$bootstrapper, 'isEnabled']) || !is_callable([$bootstrapper, 'handle'])) {
            return null;
        }

        $isEnabled = \Closure::fromCallable([$bootstrapper, 'isEnabled']);
        $handle = \Closure::fromCallable([$bootstrapper, 'handle']);
        $handleModeStrategy = $this->resolveAuthHandleModeStrategy($handle);

        return new class(
            $isEnabled,
            $handle,
            $handleModeStrategy['kind'],
            $handleModeStrategy['translate'] ?? null,
        ) implements AuthBootstrapperInterface
        {
            public function __construct(
                private readonly \Closure $isEnabled,
                private readonly \Closure $handle,
                private readonly string $handleModeStrategy,
                private readonly ?\Closure $translateMode,
            )
            {
            }

            public function isEnabled(): bool
            {
                return (bool) ($this->isEnabled)();
            }

            public function handle(object $payload, \Semitexa\Core\Auth\AuthenticationMode $mode = \Semitexa\Core\Auth\AuthenticationMode::Mandatory): ?\Semitexa\Core\Auth\AuthResult
            {
                if ($this->handleModeStrategy === 'with_translated_mode') {
                    $translateMode = $this->translateMode;
                    if ($translateMode === null) {
                        return null;
                    }

                    $translatedMode = $translateMode($mode);
                    if ($translatedMode === null) {
                        return null;
                    }

                    $result = ($this->handle)($payload, $translatedMode);
                } else {
                    $result = match ($this->handleModeStrategy) {
                        'with_mode' => ($this->handle)($payload, $mode),
                        'without_mode' => ($this->handle)($payload),
                        default => null,
                    };
                }

                return $result instanceof \Semitexa\Core\Auth\AuthResult ? $result : null;
            }
        };
    }

    /**
     * @return array{kind: 'with_mode'|'with_translated_mode'|'without_mode'|'unsupported', translate?: \Closure(\Semitexa\Core\Auth\AuthenticationMode): mixed}
     */
    private function resolveAuthHandleModeStrategy(\Closure $callable): array
    {
        $reflection = new \ReflectionFunction($callable);
        $parameters = $reflection->getParameters();
        $secondParameter = $parameters[1] ?? null;

        if ($secondParameter === null) {
            return ['kind' => 'without_mode'];
        }

        if ($secondParameter->isVariadic()) {
            return ['kind' => 'with_mode'];
        }

        $type = $secondParameter->getType();
        if ($type === null) {
            return ['kind' => 'with_mode'];
        }

        if ($this->typeAcceptsAuthenticationMode($type)) {
            return ['kind' => 'with_mode'];
        }

        $translator = $this->buildAuthenticationModeTranslator($type);
        if ($translator !== null) {
            return ['kind' => 'with_translated_mode', 'translate' => $translator];
        }

        if ($secondParameter->isOptional()) {
            return ['kind' => 'without_mode'];
        }

        return ['kind' => 'unsupported'];
    }

    private function typeAcceptsAuthenticationMode(\ReflectionType $type): bool
    {
        if ($type instanceof \ReflectionNamedType) {
            $name = $type->getName();

            return $name === 'mixed'
                || $name === 'object'
                || (!$type->isBuiltin() && is_a(\Semitexa\Core\Auth\AuthenticationMode::class, $name, true));
        }

        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $innerType) {
                if ($this->typeAcceptsAuthenticationMode($innerType)) {
                    return true;
                }
            }

            return false;
        }

        if ($type instanceof \ReflectionIntersectionType) {
            foreach ($type->getTypes() as $innerType) {
                if (!$this->typeAcceptsAuthenticationMode($innerType)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * @return \Closure(\Semitexa\Core\Auth\AuthenticationMode): mixed|null
     */
    private function buildAuthenticationModeTranslator(\ReflectionType $type): ?\Closure
    {
        if ($type instanceof \ReflectionNamedType) {
            $name = $type->getName();

            return match ($name) {
                'string' => static fn (\Semitexa\Core\Auth\AuthenticationMode $mode): string => $mode->name,
                default => $this->buildNamedAuthenticationModeTranslator($type),
            };
        }

        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $innerType) {
                $translator = $this->buildAuthenticationModeTranslator($innerType);
                if ($translator !== null) {
                    return $translator;
                }
            }
        }

        return null;
    }

    /**
     * @return \Closure(\Semitexa\Core\Auth\AuthenticationMode): mixed|null
     */
    private function buildNamedAuthenticationModeTranslator(\ReflectionNamedType $type): ?\Closure
    {
        if ($type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();
        if (!enum_exists($name)) {
            return null;
        }

        return static function (\Semitexa\Core\Auth\AuthenticationMode $mode) use ($name): mixed {
            $case = $name . '::' . $mode->name;

            return defined($case) ? constant($case) : null;
        };
    }
}
