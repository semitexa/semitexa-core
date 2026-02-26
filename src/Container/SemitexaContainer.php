<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

use Semitexa\Core\Attributes\InjectAsFactory;
use Semitexa\Core\Attributes\InjectAsMutable;
use Semitexa\Core\Attributes\InjectAsReadonly;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Registry\RegistryContractResolverGenerator;
use Semitexa\Core\Request;
use Semitexa\Core\Environment;
use Semitexa\Core\Tenant\TenantContextInterface;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Locale\LocaleContextInterface;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Custom DI container: build once per worker, get() for readonly returns shared instance,
 * for mutable returns clone(prototype) with RequestContext injected.
 * Only AsServiceContract types (from ServiceContractRegistry); injection only via protected fields with InjectAs*.
 */
final class SemitexaContainer implements ContainerInterface
{
    /** @var array<string, object> id (class/interface) => shared instance (readonly) */
    private array $readonlyInstances = [];

    /** @var array<string, object> class => prototype instance (mutable) */
    private array $mutablePrototypes = [];

    /** @var array<string, object> factory interface => ContractFactory instance */
    private array $factories = [];

    /** @var array<string, string> id => concrete class (for interfaces, resolved from registry/resolver) */
    private array $idToClass = [];

    /** @var array<string, true> classes that are ever requested as mutable */
    private array $mutableClasses = [];

    /** @var array<string, string> interface => resolver class (when resolver exists) */
    private array $interfaceToResolver = [];

    /** @var array<string, array<string, array{kind: string, type: string}>> */
    private array $injections = [];

    private ?RequestContext $requestContext = null;
    private ?TenantContextInterface $tenantContext = null;
    private ?AuthContextInterface $authContext = null;
    private ?LocaleContextInterface $localeContext = null;

    public function setRequestContext(RequestContext $context): void
    {
        $this->requestContext = $context;
    }

    public function setTenantContext(TenantContextInterface $context): void
    {
        $this->tenantContext = $context;
    }

    public function setAuthContext(AuthContextInterface $context): void
    {
        $this->authContext = $context;
    }

    public function setLocaleContext(LocaleContextInterface $context): void
    {
        $this->localeContext = $context;
    }

    /**
     * Register a pre-built instance (e.g. Environment, Logger) as readonly.
     * Call after build() for bootstrap entries that are not discovered via AsServiceContract.
     */
    public function set(string $id, object $instance): void
    {
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
        $resolverClass = $this->interfaceToResolver[$id] ?? null;
        if ($resolverClass !== null) {
            $resolver = $this->readonlyInstances[$resolverClass] ?? null;
            if ($resolver !== null && method_exists($resolver, 'getContract')) {
                $active = $resolver->getContract();
                $activeClass = $active::class;
                if (isset($this->mutablePrototypes[$activeClass])) {
                    $clone = clone $active;
                    $this->injectRequestContextInto($clone);
                    $this->injectFactoriesIntoInstance($clone, $activeClass);
                    return $clone;
                }
                return $active;
            }
        }
        $class = $this->idToClass[$id] ?? null;
        if ($class !== null && isset($this->mutablePrototypes[$class])) {
            $clone = clone $this->mutablePrototypes[$class];
            $this->injectRequestContextInto($clone);
            $this->injectFactoriesIntoInstance($clone, $class);
            return $clone;
        }
        throw new NotFoundException('Container: unknown or not registered service: ' . $id);
    }

    public function has(string $id): bool
    {
        return isset($this->readonlyInstances[$id])
            || isset($this->interfaceToResolver[$id])
            || isset($this->idToClass[$id])
            || isset($this->factories[$id]);
    }

    /**
     * Build the container (call once per worker).
     */
    public function build(): void
    {
        ClassDiscovery::initialize();
        AttributeDiscovery::initialize();
        $registry = new ServiceContractRegistry();
        $contractDetails = $registry->getContractDetails();

        // id => concrete class: from ServiceContractRegistry (AsServiceContract implementations)
        foreach ($contractDetails as $interface => $data) {
            $active = $data['active'] ?? null;
            foreach ($data['implementations'] ?? [] as $impl) {
                $implClass = $impl['class'];
                $this->idToClass[$implClass] = $implClass;
            }
            if ($active !== null) {
                $this->idToClass[$interface] = $active;
                $resolverClass = $this->getResolverClassForContract($interface);
                if ($resolverClass !== null && class_exists($resolverClass)) {
                    $this->idToClass[$resolverClass] = $resolverClass;
                    $this->interfaceToResolver[$interface] = $resolverClass;
                }
            }
        }

        // Handlers are not service contracts; register them so get($handlerClass) can resolve (mutable, per request).
        foreach (AttributeDiscovery::getDiscoveredPayloadHandlerClassNames() as $handlerClass) {
            $this->idToClass[$handlerClass] = $handlerClass;
        }

        // Mark mutable classes (any injection of type T as InjectAsMutable)
        $this->collectMutableClasses();

        // Build injection metadata for all concrete service classes
        $this->collectInjections();

        // Detect mutable-only cycles
        $this->assertNoMutableCycles();

        // Build readonly graph (shared instances; skip resolvers and mutable-only classes)
        $this->buildReadonlyGraph($registry, $contractDetails);

        // Build mutable prototypes
        $this->buildMutablePrototypes($registry, $contractDetails);

        // Build resolvers (depend on implementations from both graphs)
        $this->buildResolvers($contractDetails);

        // Build Factory* bindings (generic ContractFactory per factory interface)
        $this->buildFactories($registry, $contractDetails);

        // Inject factory instances into mutable prototypes that have InjectAsFactory
        $this->injectFactoriesIntoPrototypes();
    }

    private function injectFactoriesIntoPrototypes(): void
    {
        foreach ($this->mutablePrototypes as $class => $instance) {
            $injections = $this->injections[$class] ?? $this->getInjectionsForClass($class);
            foreach ($injections as $propName => $info) {
                if (($info['kind'] ?? '') !== 'factory') {
                    continue;
                }
                $factory = $this->factories[$info['type']] ?? null;
                if ($factory === null) {
                    continue;
                }
                try {
                    $prop = (new \ReflectionClass($instance))->getProperty($propName);
                    $prop->setAccessible(true);
                    $prop->setValue($instance, $factory);
                } catch (\Throwable $e) {
                    if (Environment::getEnvValue('APP_DEBUG') === '1') {
                        error_log("[Semitexa] SemitexaContainer::injectFactoriesIntoPrototypes failed for {$class}::\${$propName}: " . $e->getMessage());
                    }
                }
            }
        }
    }

    private function getResolverClassForContract(string $interface): ?string
    {
        if (!interface_exists($interface)) {
            return null;
        }
        $short = (new ReflectionClass($interface))->getShortName();
        $resolverShort = preg_replace('/Interface$/', 'Resolver', $short);
        if ($resolverShort === $short) {
            $resolverShort = $short . 'Resolver';
        }
        return 'App\\Registry\\Contracts\\' . $resolverShort;
    }

    private function collectMutableClasses(): void
    {
        foreach (array_keys($this->idToClass) as $id) {
            $class = $this->resolveToClass($id);
            if ($class === null) {
                continue;
            }
            foreach ($this->getInjectionsForClass($class) as $prop => $info) {
                if ($info['kind'] === 'mutable') {
                    $target = $info['type'];
                    $concrete = $this->resolveToClass($target);
                    if ($concrete !== null) {
                        $this->mutableClasses[$concrete] = true;
                    }
                }
            }
        }
        // Classes that are get()ed directly (e.g. handlers) should be mutable so we clone per request.
        foreach ($this->idToClass as $id => $class) {
            if (interface_exists($id)) {
                continue;
            }
            if (str_contains($class, 'Handler')) {
                $this->mutableClasses[$class] = true;
            }
        }
    }

    private function resolveToClass(string $id): ?string
    {
        if (isset($this->idToClass[$id])) {
            return $this->idToClass[$id];
        }
        if (class_exists($id) && !interface_exists($id)) {
            return $id;
        }
        return null;
    }

    /** @return array<string, array{kind: string, type: string}> */
    private function getInjectionsForClass(string $class): array
    {
        $out = [];
        try {
            $ref = new ReflectionClass($class);
            foreach ($ref->getProperties() as $prop) {
                if (!$prop->isProtected()) {
                    continue;
                }
                $type = $prop->getType();
                if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }
                $typeName = $type->getName();
                if ($prop->getAttributes(InjectAsReadonly::class) !== []) {
                    $out[$prop->getName()] = ['kind' => 'readonly', 'type' => $typeName];
                } elseif ($prop->getAttributes(InjectAsMutable::class) !== []) {
                    $out[$prop->getName()] = ['kind' => 'mutable', 'type' => $typeName];
                } elseif ($prop->getAttributes(InjectAsFactory::class) !== []) {
                    $out[$prop->getName()] = ['kind' => 'factory', 'type' => $typeName];
                }
            }
        } catch (\Throwable $e) {
            if (Environment::getEnvValue('APP_DEBUG') === '1') {
                error_log("[Semitexa] SemitexaContainer::getInjectionsForClass({$class}) reflection failed: " . $e->getMessage());
            }
        }
        return $out;
    }

    private function collectInjections(): void
    {
        $seen = [];
        foreach ($this->idToClass as $id => $class) {
            if (!interface_exists($id)) {
                $seen[$class] = true;
            }
        }
        foreach (array_keys($this->mutableClasses) as $class) {
            $seen[$class] = true;
        }
        foreach (array_keys($seen) as $class) {
            $this->injections[$class] = $this->getInjectionsForClass($class);
        }
    }

    private function assertNoMutableCycles(): void
    {
        foreach (array_keys($this->mutableClasses) as $class) {
            $this->visitMutable($class, [], []);
        }
    }

    /** @param array<string> $path */
    private function visitMutable(string $class, array $path, array $visited): void
    {
        if (isset($visited[$class])) {
            return;
        }
        $visited[$class] = true;
        $path[] = $class;
        $injections = $this->getInjectionsForClass($class);
        foreach ($injections as $info) {
            if (($info['kind'] ?? '') !== 'mutable') {
                continue;
            }
            $targetClass = $this->resolveToClass($info['type']);
            if ($targetClass === null || !isset($this->mutableClasses[$targetClass])) {
                continue;
            }
            if (in_array($targetClass, $path, true)) {
                throw new \RuntimeException(
                    'DI: mutable cycle detected: ' . implode(' -> ', $path) . ' -> ' . $targetClass
                );
            }
            $this->visitMutable($targetClass, $path, $visited);
        }
    }

    /** @param array<string, array{implementations: list<array{module: string, class: string}>, active: string}> $contractDetails */
    private function buildReadonlyGraph(ServiceContractRegistry $registry, array $contractDetails): void
    {
        $readonlyClasses = [];
        foreach ($this->idToClass as $id => $class) {
            if (isset($this->mutableClasses[$class])) {
                continue;
            }
            if (interface_exists($id)) {
                continue;
            }
            if ($this->isResolverClass($class)) {
                continue;
            }
            $readonlyClasses[$class] = true;
        }
        $order = $this->topologicalOrder(array_keys($readonlyClasses), 'readonly');
        foreach ($order as $class) {
            $instance = $this->createInstance($class, $contractDetails, true);
            $this->readonlyInstances[$class] = $instance;
            foreach ($this->idToClass as $id => $c) {
                if ($c === $class && $id !== $class) {
                    $this->readonlyInstances[$id] = $instance;
                }
            }
        }
    }

    private function isResolverClass(string $class): bool
    {
        return str_contains($class, '\\Registry\\Contracts\\') && str_ends_with($class, 'Resolver');
    }

    /** @param array<string, array{implementations: list<array{module: string, class: string}>, active: string}> $contractDetails */
    private function buildResolvers(array $contractDetails): void
    {
        foreach ($contractDetails as $interface => $data) {
            $resolverClass = $this->getResolverClassForContract($interface);
            if ($resolverClass === null || !class_exists($resolverClass) || !isset($this->idToClass[$resolverClass])) {
                continue;
            }
            $resolver = $this->createInstanceWithConstructor($resolverClass);
            $this->readonlyInstances[$resolverClass] = $resolver;
        }
    }

    /** @param array<string, array{implementations: list<array{module: string, class: string}>, active: string}> $contractDetails */
    private function buildMutablePrototypes(ServiceContractRegistry $registry, array $contractDetails): void
    {
        $order = $this->topologicalOrder(array_keys($this->mutableClasses), 'mutable');
        foreach ($order as $class) {
            $prototype = $this->createInstance($class, $contractDetails, false);
            $this->mutablePrototypes[$class] = $prototype;
            $this->idToClass[$class] = $class;
            foreach ($this->idToClass as $id => $c) {
                if ($c === $class && $id !== $class) {
                    $this->idToClass[$id] = $class;
                }
            }
        }
    }

    /**
     * @param array<string> $classes
     * @return array<string>
     */
    private function topologicalOrder(array $classes, string $graphKind): array
    {
        $dep = [];
        foreach ($classes as $c) {
            $dep[$c] = [];
            foreach ($this->getInjectionsForClass($c) as $info) {
                if ($info['kind'] === 'factory') {
                    continue;
                }
                $target = $this->resolveToClass($info['type']);
                if ($target !== null && in_array($target, $classes, true)) {
                    $dep[$c][] = $target;
                }
            }
        }
        $out = [];
        $visited = [];
        $visit = function (string $c) use (&$visit, $dep, $classes, &$out, &$visited) {
            if (isset($visited[$c])) {
                return;
            }
            $visited[$c] = true;
            foreach ($dep[$c] ?? [] as $d) {
                if (in_array($d, $classes, true)) {
                    $visit($d);
                }
            }
            $out[] = $c;
        };
        foreach ($classes as $c) {
            $visit($c);
        }
        return $out;
    }

    /**
     * Resolve constructor params from container and create instance; then set Inject* properties.
     * Used for resolvers and any class that has constructor dependencies.
     */
    private function createInstanceWithConstructor(string $class): object
    {
        $ref = new ReflectionClass($class);
        $ctor = $ref->getConstructor();
        $args = [];
        if ($ctor !== null) {
            foreach ($ctor->getParameters() as $param) {
                $type = $param->getType();
                if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    throw new \RuntimeException("Container: cannot resolve constructor param \${$param->getName()} for {$class}");
                }
                $name = $type->getName();
                $inst = $this->readonlyInstances[$name] ?? $this->readonlyInstances[$this->idToClass[$name] ?? ''] ?? null
                    ?? $this->mutablePrototypes[$name] ?? $this->mutablePrototypes[$this->idToClass[$name] ?? ''] ?? null;
                if ($inst === null) {
                    throw new \RuntimeException("Container: missing dependency for {$class}::__construct(\${$param->getName()}: {$name})");
                }
                $args[] = $inst;
            }
        }
        $instance = $args !== [] ? $ref->newInstanceArgs($args) : $ref->newInstance();
        $this->injectPropertiesInto($instance, $class);
        return $instance;
    }

    /**
     * @param array<string, array{implementations: list<array{module: string, class: string}>, active: string}> $contractDetails
     */
    private function createInstance(string $class, array $contractDetails, bool $readonly): object
    {
        $ref = new ReflectionClass($class);
        $ctor = $ref->getConstructor();
        $args = [];
        if ($ctor !== null) {
            foreach ($ctor->getParameters() as $param) {
                $type = $param->getType();
                if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                    $name = $type->getName();
                    $inst = $this->readonlyInstances[$name] ?? $this->readonlyInstances[$this->idToClass[$name] ?? ''] ?? null
                        ?? $this->mutablePrototypes[$name] ?? $this->mutablePrototypes[$this->idToClass[$name] ?? ''] ?? null;
                    if ($inst !== null) {
                        $args[] = $inst;
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
        try {
            $instance = $args !== [] ? $ref->newInstanceArgs($args) : $ref->newInstance();
        } catch (\Throwable $e) {
            throw new \RuntimeException("Container: cannot instantiate {$class}: " . $e->getMessage(), 0, $e);
        }
        $this->injectPropertiesInto($instance, $class);
        return $instance;
    }

    private function injectPropertiesInto(object $instance, string $class): void
    {
        $ref = new ReflectionClass($instance);
        $injections = $this->injections[$class] ?? $this->getInjectionsForClass($class);
        foreach ($injections as $propName => $info) {
            try {
                $prop = $ref->getProperty($propName);
            } catch (\Throwable $e) {
                if (Environment::getEnvValue('APP_DEBUG') === '1') {
                    error_log("[Semitexa] SemitexaContainer::injectPropertiesInto({$class}) property {$propName} not found: " . $e->getMessage());
                }
                continue;
            }
            $prop->setAccessible(true);
            $kind = $info['kind'];
            $typeName = $info['type'];
            if ($kind === 'factory') {
                $factory = $this->factories[$typeName] ?? null;
                if ($factory !== null) {
                    $prop->setValue($instance, $factory);
                }
                continue;
            }
            $targetClass = $this->resolveToClass($typeName);
            if ($targetClass === null) {
                continue;
            }
            if ($kind === 'readonly') {
                $dep = $this->readonlyInstances[$typeName] ?? $this->readonlyInstances[$targetClass] ?? null;
                if ($dep !== null) {
                    $prop->setValue($instance, $dep);
                }
            } else {
                $dep = $this->mutablePrototypes[$targetClass] ?? null;
                if ($dep !== null) {
                    $prop->setValue($instance, $dep);
                }
            }
        }
    }

    /** @param array<string, array{implementations: list<array{module: string, class: string}>, active: string}> $contractDetails */
    private function buildFactories(ServiceContractRegistry $registry, array $contractDetails): void
    {
        foreach ($contractDetails as $baseInterface => $data) {
            $implementations = $data['implementations'] ?? [];
            if (count($implementations) < 2) {
                continue;
            }
            $factoryInterface = RegistryContractResolverGenerator::getFactoryInterfaceForContract($baseInterface);
            if ($factoryInterface === null || !interface_exists($factoryInterface)) {
                continue;
            }
            $generatedClass = RegistryContractResolverGenerator::getGeneratedFactoryClassForContract($baseInterface);
            if (class_exists($generatedClass)) {
                $instance = $this->createInstanceWithConstructor($generatedClass);
                $this->factories[$factoryInterface] = $instance;
                continue;
            }
            $active = $data['active'];
            $defaultImpl = null;
            $resolverClass = $this->interfaceToResolver[$baseInterface] ?? null;
            if ($resolverClass !== null) {
                $resolver = $this->readonlyInstances[$resolverClass] ?? null;
                if ($resolver !== null) {
                    $defaultImpl = $resolver->getContract();
                }
            }
            if ($defaultImpl === null) {
                $defaultImpl = $this->readonlyInstances[$active] ?? $this->mutablePrototypes[$active] ?? null;
            }
            if ($defaultImpl === null) {
                continue;
            }
            $byKey = [];
            foreach ($implementations as $impl) {
                $implClass = $impl['class'];
                $module = $impl['module'];
                $shortName = (new ReflectionClass($implClass))->getShortName();
                $key = $module . '::' . $shortName;
                $inst = $this->readonlyInstances[$implClass] ?? $this->mutablePrototypes[$implClass] ?? null;
                if ($inst !== null) {
                    $byKey[$key] = $inst;
                }
            }
            $this->factories[$factoryInterface] = new ContractFactory($defaultImpl, $byKey);
        }
    }

    private function injectFactoriesIntoInstance(object $instance, string $class): void
    {
        $injections = $this->injections[$class] ?? $this->getInjectionsForClass($class);
        foreach ($injections as $propName => $info) {
            if (($info['kind'] ?? '') !== 'factory') {
                continue;
            }
            $typeName = $info['type'] ?? '';
            $factory = $this->factories[$typeName] ?? null;
            if ($factory === null) {
                continue;
            }
            $ref = new ReflectionClass($instance);
            if (!$ref->hasProperty($propName)) {
                continue;
            }
            $prop = $ref->getProperty($propName);
            $prop->setAccessible(true);
            $prop->setValue($instance, $factory);
        }
    }

    private function injectRequestContextInto(object $instance): void
    {
        $ref = new ReflectionClass($instance);
        foreach ($ref->getProperties() as $prop) {
            if (!$prop->isProtected() && !$prop->isPublic()) {
                continue;
            }
            $type = $prop->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            $name = $type->getName();
            $prop->setAccessible(true);
            if ($name === Request::class) {
                $prop->setValue($instance, $this->requestContext?->request);
            } elseif ($name === \Semitexa\Core\Session\SessionInterface::class) {
                $prop->setValue($instance, $this->requestContext?->session);
            } elseif ($name === \Semitexa\Core\Cookie\CookieJarInterface::class) {
                $prop->setValue($instance, $this->requestContext?->cookieJar);
            } elseif ($name === TenantContextInterface::class && $this->tenantContext !== null) {
                $prop->setValue($instance, $this->tenantContext);
            } elseif ($name === AuthContextInterface::class && $this->authContext !== null) {
                $prop->setValue($instance, $this->authContext);
            } elseif ($name === LocaleContextInterface::class && $this->localeContext !== null) {
                $prop->setValue($instance, $this->localeContext);
            }
        }
    }
}
