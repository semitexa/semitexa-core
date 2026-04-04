<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Container\Exception\ContainerSealedException;
use Semitexa\Core\Container\Exception\InjectionException;
use Semitexa\Core\Cookie\CookieJarInterface;
use Semitexa\Core\Locale\LocaleContextInterface;
use Semitexa\Core\Request;
use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Tenant\TenantContextInterface;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Custom DI container: build once per worker, get() for readonly returns shared instance,
 * for execution-scoped returns clone(prototype) with execution context injected.
 *
 * Four property attributes are the only value channels for container-managed framework objects:
 * - #[InjectAsReadonly] — worker-scoped service
 * - #[InjectAsMutable] — execution-scoped service or context
 * - #[InjectAsFactory] — multi-implementation factory
 * - #[Config] — scalar configuration value
 *
 * Constructors with parameters are forbidden on container-managed framework objects.
 * Container is sealed after boot — set() throws ContainerSealedException.
 */
final class SemitexaContainer implements ContainerInterface
{
    /** @var array<string, object> id (class/interface) => shared instance (readonly) */
    private array $readonlyInstances = [];

    /** @var array<string, object> class => prototype instance (execution-scoped) */
    private array $executionScopedPrototypes = [];

    /** @var array<string, object> factory interface => ContractFactory instance */
    private array $factories = [];

    /** @var array<string, class-string> id => concrete class (for interfaces, resolved from registry/resolver) */
    private array $idToClass = [];

    /** @var array<class-string, true> classes that are execution-scoped */
    private array $executionScopedClasses = [];

    /** @var array<class-string, class-string> interface => resolver class (when resolver exists) */
    private array $interfaceToResolver = [];

    /** @var array<class-string, array<string, array{kind: string, type: class-string}>> */
    private array $injections = [];

    /** @var array<string, object> type => execution context value (Request, Session, etc.) */
    private array $executionContextValues = [];

    /** @var bool Whether the container is sealed (after BootPhase::Ready) */
    private bool $sealed = false;

    /** @var list<string> Known execution context types, resolved at execution time not boot time */
    public const EXECUTION_CONTEXT_TYPES = [
        Request::class,
        SessionInterface::class,
        CookieJarInterface::class,
        TenantContextInterface::class,
        AuthContextInterface::class,
        LocaleContextInterface::class,
    ];

    /**
     * Set execution-scoped context values. Called once per execution before handler resolution.
     * These are resolved by #[InjectAsMutable] during clone-time injection.
     */
    public function setExecutionContext(ExecutionContext $context): void
    {
        $this->executionContextValues = [];
        if ($context->request !== null) {
            $this->executionContextValues[Request::class] = $context->request;
        }
        if ($context->session !== null) {
            $this->executionContextValues[SessionInterface::class] = $context->session;
        }
        if ($context->cookieJar !== null) {
            $this->executionContextValues[CookieJarInterface::class] = $context->cookieJar;
        }
        if ($context->tenantContext !== null) {
            $this->executionContextValues[TenantContextInterface::class] = $context->tenantContext;
        }
        if ($context->authContext !== null) {
            $this->executionContextValues[AuthContextInterface::class] = $context->authContext;
        }
        if ($context->localeContext !== null) {
            $this->executionContextValues[LocaleContextInterface::class] = $context->localeContext;
        }
    }

    /**
     * Register a pre-built instance (e.g. Environment, Logger) as readonly.
     * Only allowed during build phase. After BootPhase::Ready, throws ContainerSealedException.
     */
    public function set(string $id, object $instance): void
    {
        if ($this->sealed) {
            throw new ContainerSealedException(
                "Cannot modify container after boot. All registrations must happen during build(). "
                . "Attempted to set: {$id}"
            );
        }
        $this->readonlyInstances[$id] = $instance;
    }

    public function get(string $id): object
    {
        if (isset($this->readonlyInstances[$id])) {
            return $this->readonlyInstances[$id];
        }
        if (isset($this->factories[$id])) {
            return $this->factories[$id];
        }

        $class = $this->idToClass[$id] ?? null;
        if ($class !== null && isset($this->executionScopedPrototypes[$class])) {
            $clone = clone $this->executionScopedPrototypes[$class];
            $this->injectMutableProperties($clone, $class);
            return $clone;
        }

        // Interface -> resolver -> active contract
        $resolverClass = $this->interfaceToResolver[$id] ?? null;
        if ($resolverClass !== null) {
            $resolver = $this->readonlyInstances[$resolverClass] ?? null;
            if ($resolver !== null && method_exists($resolver, 'getContract')) {
                $active = $resolver->getContract();
                $activeClass = $active::class;
                if (isset($this->executionScopedPrototypes[$activeClass])) {
                    $clone = clone $active;
                    $this->injectMutableProperties($clone, $activeClass);
                    return $clone;
                }
                return $active;
            }
        }

        throw new NotFoundException('Container: unknown service: ' . $id);
    }

    public function has(string $id): bool
    {
        return isset($this->readonlyInstances[$id])
            || isset($this->interfaceToResolver[$id])
            || isset($this->idToClass[$id])
            || isset($this->factories[$id]);
    }

    /**
     * Like get(), but returns null instead of throwing when the service is not found.
     * Use for optional dependencies where absence is a valid state.
     */
    public function getOrNull(string $id): ?object
    {
        try {
            return $this->get($id);
        } catch (NotFoundException) {
            return null;
        }
    }

    /**
     * Auto-wire and create a class instance that is not pre-registered in the container.
     * Constructor dependencies are resolved from readonly/execution-scoped pools.
     *
     * @internal Used only for non-container-managed classes (e.g. Symfony Console commands).
     */
    public function resolve(string $class): object
    {
        $ref = new ReflectionClass($class);
        $ctor = $ref->getConstructor();
        $args = [];
        if ($ctor !== null) {
            foreach ($ctor->getParameters() as $param) {
                $type = $param->getType();
                if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                    $name = $type->getName();
                    if ($this->has($name)) {
                        $args[] = $this->get($name);
                        continue;
                    }
                }
                if ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();
                    continue;
                }
                throw new \RuntimeException("Container: cannot resolve constructor param \${$param->getName()} for {$class}");
            }
        }
        return $args !== [] ? $ref->newInstanceArgs($args) : $ref->newInstance();
    }

    /**
     * Build the container (call once per worker).
     */
    public function build(): void
    {
        $injectionAnalyzer = new InjectionAnalyzer();
        $graphBuilder = new GraphBuilder($injectionAnalyzer);
        $cycleDetector = new CycleDetector();
        $bootstrapper = new ContainerBootstrapper($injectionAnalyzer, $graphBuilder, $cycleDetector);

        $bootstrapper->build(
            readonlyInstances: $this->readonlyInstances,
            executionScopedPrototypes: $this->executionScopedPrototypes,
            factories: $this->factories,
            idToClass: $this->idToClass,
            executionScopedClasses: $this->executionScopedClasses,
            interfaceToResolver: $this->interfaceToResolver,
            injections: $this->injections,
        );

        // === BootPhase::Ready ===
        $this->sealed = true;
    }

    /**
     * Re-inject all #[InjectAsMutable] properties from the current execution context
     * and execution-scoped service pool. Called on each clone at execution time.
     */
    private function injectMutableProperties(object $instance, string $class): void
    {
        $injections = $this->injections[$class] ?? [];
        $ref = new ReflectionClass($instance);

        foreach ($injections as $propName => $info) {
            if ($info['kind'] !== 'mutable') {
                continue;
            }

            $typeName = $info['type'];

            // Try execution context first (Request, Session, etc.)
            if (isset($this->executionContextValues[$typeName])) {
                $prop = $ref->getProperty($propName);
                $prop->setAccessible(true);
                $prop->setValue($instance, $this->executionContextValues[$typeName]);
                continue;
            }

            // Try execution-scoped prototypes (clone them)
            $protoClass = $this->idToClass[$typeName] ?? $typeName;
            $proto = $this->executionScopedPrototypes[$protoClass]
                ?? $this->executionScopedPrototypes[$typeName]
                ?? null;
            if ($proto !== null) {
                $prop = $ref->getProperty($propName);
                $prop->setAccessible(true);
                $prop->setValue($instance, clone $proto);
                continue;
            }

            throw new InjectionException(
                targetClass: $class,
                propertyName: $propName,
                propertyType: $typeName,
                injectionKind: 'mutable',
                message: "Cannot inject {$class}::\${$propName} (type: {$typeName}) "
                    . "at execution time. No execution context value or prototype found.",
            );
        }

        // Also inject factories into cloned instances
        foreach ($injections as $propName => $info) {
            if ($info['kind'] !== 'factory') {
                continue;
            }
            $factory = $this->factories[$info['type']] ?? null;
            if ($factory !== null) {
                $prop = $ref->getProperty($propName);
                $prop->setAccessible(true);
                $prop->setValue($instance, $factory);
            }
        }
    }
}
