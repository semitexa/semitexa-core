<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Container\BuildPhase\BuildContext;
use Semitexa\Core\Container\Exception\ContainerSealedException;
use Semitexa\Core\Container\Exception\InjectionException;
use Semitexa\Core\Container\Store\InjectionMap;
use Semitexa\Core\Container\Store\InstanceStore;
use Semitexa\Core\Container\Store\TypeMap;
use Semitexa\Core\Exception\ContainerException;
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
 *
 * Internal state is organized into three typed stores:
 * - InstanceStore: readonly instances, execution-scoped prototypes, factories
 * - TypeMap: contract bindings, registered classes, resolver mappings, scope detection
 * - InjectionMap: per-class property injection metadata
 */
final class SemitexaContainer implements ContainerInterface
{
    private InstanceStore $instanceStore;
    private TypeMap $typeMap;
    private InjectionMap $injectionMap;

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

    public function __construct()
    {
        $this->instanceStore = new InstanceStore();
        $this->typeMap = new TypeMap();
        $this->injectionMap = new InjectionMap();
    }

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

    public function clearExecutionContext(): void
    {
        $this->executionContextValues = [];
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
        $this->instanceStore->readonly[$id] = $instance;
    }

    public function get(string $id): object
    {
        // 1. Direct readonly instance
        if (isset($this->instanceStore->readonly[$id])) {
            return $this->instanceStore->readonly[$id];
        }

        // 2. Factory
        if (isset($this->instanceStore->factories[$id])) {
            return $this->instanceStore->factories[$id];
        }

        // 3. Resolve id to concrete class via TypeMap
        $class = $this->typeMap->resolveClass($id);
        if ($class !== null) {
            // Check readonly by concrete class (handles interface → concrete resolution)
            if (isset($this->instanceStore->readonly[$class])) {
                return $this->instanceStore->readonly[$class];
            }

            // Check execution-scoped prototype
            if (isset($this->instanceStore->prototypes[$class])) {
                $clone = clone $this->instanceStore->prototypes[$class];
                $this->injectMutableProperties($clone, $class);
                return $clone;
            }
        }

        // 4. Interface → resolver → active contract
        $resolverClass = $this->typeMap->interfaceToResolver[$id] ?? null;
        if ($resolverClass !== null) {
            $resolver = $this->instanceStore->readonly[$resolverClass] ?? null;
            if ($resolver !== null && method_exists($resolver, 'getContract')) {
                $active = $resolver->getContract();
                $activeClass = $active::class;
                if (isset($this->instanceStore->prototypes[$activeClass])) {
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
        return isset($this->instanceStore->readonly[$id])
            || $this->typeMap->isKnown($id)
            || isset($this->instanceStore->factories[$id]);
    }

    /**
     * Check whether a service ID resolves to an execution-scoped prototype.
     */
    public function isExecutionScoped(string $id): bool
    {
        $class = $this->typeMap->resolveClass($id) ?? $id;

        return isset($this->instanceStore->prototypes[$class]);
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
                throw new ContainerException("Container: cannot resolve constructor param \${$param->getName()} for {$class}");
            }
        }
        return $args !== [] ? $ref->newInstanceArgs($args) : $ref->newInstance();
    }

    /**
     * Build the container (call once per worker).
     */
    public function build(): void
    {
        $context = new BuildContext($this->instanceStore, $this->typeMap, $this->injectionMap);
        $bootstrapper = new ContainerBootstrapper();
        $bootstrapper->build($context);

        // === BootPhase::Ready ===
        $this->sealed = true;
    }

    /**
     * Re-inject all #[InjectAsMutable] properties from the current execution context
     * and execution-scoped service pool. Called on each clone at execution time.
     */
    private function injectMutableProperties(object $instance, string $class): void
    {
        $injections = $this->injectionMap->injections[$class] ?? [];
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
            $protoClass = $this->typeMap->resolveClass($typeName) ?? $typeName;
            $proto = $this->instanceStore->prototypes[$protoClass]
                ?? $this->instanceStore->prototypes[$typeName]
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
            $factory = $this->instanceStore->factories[$info['type']] ?? null;
            if ($factory !== null) {
                $prop = $ref->getProperty($propName);
                $prop->setAccessible(true);
                $prop->setValue($instance, $factory);
            }
        }
    }
}
