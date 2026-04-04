<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

use Semitexa\Core\Attributes\AsEventListener;
use Semitexa\Core\Attributes\AsPipelineListener;
use Semitexa\Core\Attributes\AsService;
use Semitexa\Core\Attributes\Config;
use Semitexa\Core\Attributes\ExecutionScoped;
use Semitexa\Core\Attributes\InjectAsFactory;
use Semitexa\Core\Attributes\InjectAsMutable;
use Semitexa\Core\Attributes\InjectAsReadonly;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Container\Exception\CircularDependencyException;
use Semitexa\Core\Container\Exception\ContainerBuildException;
use Semitexa\Core\Container\Exception\ContainerSealedException;
use Semitexa\Core\Container\Exception\InjectionException;
use Semitexa\Core\Cookie\CookieJarInterface;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\BootDiagnostics;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Environment;
use Semitexa\Core\Locale\LocaleContextInterface;
use Semitexa\Core\Pipeline\AuthCheck;
use Semitexa\Core\Pipeline\HandleRequest;
use Semitexa\Core\Pipeline\PipelineListenerRegistry;
use Semitexa\Core\Registry\RegistryContractResolverGenerator;
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

    /** @var array<string, string> id => concrete class (for interfaces, resolved from registry/resolver) */
    private array $idToClass = [];

    /** @var array<string, true> classes that are execution-scoped */
    private array $executionScopedClasses = [];

    /** @var array<string, string> interface => resolver class (when resolver exists) */
    private array $interfaceToResolver = [];

    /** @var array<string, array<string, array{kind: string, type: string}>> */
    private array $injections = [];

    /** @var array<string, object> type => execution context value (Request, Session, etc.) */
    private array $executionContextValues = [];

    /** @var bool Whether the container is sealed (after BootPhase::Ready) */
    private bool $sealed = false;

    /** @var list<string> Known execution context types, resolved at execution time not boot time */
    private const EXECUTION_CONTEXT_TYPES = [
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
        BootDiagnostics::begin();

        // === BootPhase::ClassmapLoad ===
        ClassDiscovery::initialize();

        // === BootPhase::ModuleDiscovery ===
        \Semitexa\Core\ModuleRegistry::initialize();

        // === BootPhase::AttributeScan ===
        AttributeDiscovery::initialize();

        // === BootPhase::ContractResolution ===
        $registry = new ServiceContractRegistry();
        $contractDetails = $registry->getContractDetails();
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

        // === BootPhase::ServiceRegistration ===
        foreach (AttributeDiscovery::getDiscoveredPayloadHandlerClassNames() as $handlerClass) {
            $this->idToClass[$handlerClass] = $handlerClass;
        }
        foreach (ClassDiscovery::findClassesWithAttribute(AsService::class) as $serviceClass) {
            $this->idToClass[$serviceClass] = $serviceClass;
        }
        if (class_exists(\Semitexa\Orm\Discovery\RepositoryDiscovery::class)) {
            foreach (\Semitexa\Orm\Discovery\RepositoryDiscovery::discoverRepositoryClasses() as $repositoryClass) {
                $this->idToClass[$repositoryClass] = $repositoryClass;
            }
        }
        // Auth handlers need execution-scoped injection
        if (class_exists(\Semitexa\Auth\Attribute\AsAuthHandler::class)) {
            foreach (ClassDiscovery::findClassesWithAttribute(\Semitexa\Auth\Attribute\AsAuthHandler::class) as $handlerClass) {
                $this->idToClass[$handlerClass] = $handlerClass;
            }
        }
        // Pipeline listeners are resolved per execution
        foreach ([AuthCheck::class, HandleRequest::class] as $phaseClass) {
            foreach (PipelineListenerRegistry::getListeners($phaseClass) as $meta) {
                $this->idToClass[$meta['class']] = $meta['class'];
            }
        }

        // === BootPhase::ScopeDetection ===
        $this->collectExecutionScopedClasses();

        // === BootPhase::InjectionAnalysis ===
        $this->collectInjections();

        // === BootPhase::CycleDetection ===
        $this->assertNoCycles();

        // === BootPhase::ReadonlyBuild ===
        $this->buildReadonlyGraph();

        // === BootPhase::ExecutionScopedBuild ===
        $this->buildExecutionScopedPrototypes();

        // === BootPhase::ResolverBuild ===
        $this->buildResolvers($contractDetails);

        // === BootPhase::FactoryBuild ===
        $this->buildFactories($registry, $contractDetails);

        // Inject factory instances into execution-scoped prototypes that have InjectAsFactory
        $this->injectFactoriesIntoPrototypes();

        // === BootPhase::Validation ===
        $this->validateAllBindings();

        // === BootPhase::Ready ===
        $this->sealed = true;

        $strictMode = filter_var(getenv('BOOT_STRICT_MODE'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        BootDiagnostics::current()->finalize(strict: $strictMode);
    }

    /**
     * Collect execution-scoped classes by explicit #[ExecutionScoped] attribute
     * and implied by handler/listener attributes. No name-string fallback.
     */
    private function collectExecutionScopedClasses(): void
    {
        // Explicit #[ExecutionScoped] attribute
        foreach ($this->idToClass as $id => $class) {
            if (interface_exists($id)) {
                continue;
            }
            try {
                $ref = new ReflectionClass($class);
            } catch (\Throwable) {
                continue;
            }
            if ($ref->getAttributes(ExecutionScoped::class) !== []) {
                $this->executionScopedClasses[$class] = true;
            }
        }

        // Implied by #[AsPayloadHandler]
        foreach (AttributeDiscovery::getDiscoveredPayloadHandlerClassNames() as $handlerClass) {
            $this->executionScopedClasses[$handlerClass] = true;
        }

        // Implied by #[AsEventListener]
        foreach (ClassDiscovery::findClassesWithAttribute(AsEventListener::class) as $listenerClass) {
            $this->executionScopedClasses[$listenerClass] = true;
        }

        // Implied by #[AsPipelineListener]
        foreach (ClassDiscovery::findClassesWithAttribute(AsPipelineListener::class) as $listenerClass) {
            $this->executionScopedClasses[$listenerClass] = true;
        }

        // Auth handlers are execution-scoped
        if (class_exists(\Semitexa\Auth\Attribute\AsAuthHandler::class)) {
            foreach (ClassDiscovery::findClassesWithAttribute(\Semitexa\Auth\Attribute\AsAuthHandler::class) as $handlerClass) {
                $this->executionScopedClasses[$handlerClass] = true;
            }
        }
    }

    /**
     * Collect injection metadata for all registered classes.
     * Validates:
     * - Visibility: only protected allowed
     * - Type boundary: #[InjectAs*] on class/interface types only, #[Config] on scalars only
     * - No #[InjectAs*] or #[Config] inside traits
     */
    private function collectInjections(): void
    {
        $seen = [];
        foreach ($this->idToClass as $id => $class) {
            if (!interface_exists($id)) {
                $seen[$class] = true;
            }
        }
        foreach (array_keys($this->executionScopedClasses) as $class) {
            $seen[$class] = true;
        }

        foreach (array_keys($seen) as $class) {
            $this->injections[$class] = $this->buildInjectionsForClass($class);
        }
    }

    /**
     * Build injection metadata for a single class with strict validation.
     *
     * @return array<string, array{kind: string, type: string}>
     */
    private function buildInjectionsForClass(string $class): array
    {
        $out = [];
        try {
            $ref = new ReflectionClass($class);
        } catch (\Throwable) {
            return [];
        }

        foreach ($ref->getProperties() as $prop) {
            $injectAttrs = [
                ...array_map(fn($a) => ['attr' => $a, 'kind' => 'readonly'],
                    $prop->getAttributes(InjectAsReadonly::class)),
                ...array_map(fn($a) => ['attr' => $a, 'kind' => 'mutable'],
                    $prop->getAttributes(InjectAsMutable::class)),
                ...array_map(fn($a) => ['attr' => $a, 'kind' => 'factory'],
                    $prop->getAttributes(InjectAsFactory::class)),
            ];

            $configAttrs = $prop->getAttributes(Config::class);

            if (empty($injectAttrs) && empty($configAttrs)) {
                continue;
            }

            if (count($injectAttrs) > 1) {
                $kinds = implode(', ', array_map(static fn(array $attr): string => $attr['kind'], $injectAttrs));
                throw new InjectionException(
                    targetClass: $class,
                    propertyName: $prop->getName(),
                    propertyType: (string) $prop->getType(),
                    injectionKind: $injectAttrs[0]['kind'],
                    message: "Property {$class}::\${$prop->getName()} declares multiple #[InjectAs*] attributes ({$kinds}). "
                        . 'Choose exactly one injection mode.',
                );
            }

            if (!empty($injectAttrs) && !empty($configAttrs)) {
                throw new InjectionException(
                    targetClass: $class,
                    propertyName: $prop->getName(),
                    propertyType: (string) $prop->getType(),
                    injectionKind: $injectAttrs[0]['kind'],
                    message: "Property {$class}::\${$prop->getName()} cannot combine #[Config] with #[InjectAs*]. "
                        . 'Choose configuration injection or service injection, but not both.',
                );
            }

            // Validate: no #[InjectAs*] or #[Config] inside traits
            $declaringTrait = $this->findDeclaringTraitForProperty($ref, $prop->getName());
            if ($declaringTrait !== null) {
                $attrName = !empty($injectAttrs) ? '#[InjectAs*]' : '#[Config]';
                throw new InjectionException(
                    targetClass: $class,
                    propertyName: $prop->getName(),
                    propertyType: (string) $prop->getType(),
                    injectionKind: !empty($injectAttrs) ? $injectAttrs[0]['kind'] : 'config',
                    message: "{$attrName} is forbidden inside traits. Property {$declaringTrait}::\${$prop->getName()} "
                        . "must be moved to the consuming class {$class}.",
                );
            }

            // Validate visibility: protected is the only allowed visibility
            if (!$prop->isProtected()) {
                $visibility = $prop->isPrivate() ? 'private' : 'public';
                $attrKind = !empty($injectAttrs) ? $injectAttrs[0]['kind'] : 'config';
                throw new InjectionException(
                    targetClass: $class,
                    propertyName: $prop->getName(),
                    propertyType: (string) $prop->getType(),
                    injectionKind: $attrKind,
                    message: "Cannot inject into {$visibility} property {$class}::\${$prop->getName()}. "
                        . "Injected properties must be protected. No exceptions.",
                );
            }

            // Handle #[Config] properties — skip for injection metadata (handled separately)
            if (!empty($configAttrs)) {
                // #[Config] validation happens in injectConfigProperties during createInstance
                continue;
            }

            // Validate type for #[InjectAs*]: must be class or interface type
            $type = $prop->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                throw new InjectionException(
                    targetClass: $class,
                    propertyName: $prop->getName(),
                    propertyType: (string) $type,
                    injectionKind: $injectAttrs[0]['kind'],
                    message: "Injected property {$class}::\${$prop->getName()} must have "
                        . "a class or interface type, got: {$type}. "
                        . "For scalar configuration values, use #[Config] instead.",
                );
            }

            $out[$prop->getName()] = [
                'kind' => $injectAttrs[0]['kind'],
                'type' => $type->getName(),
            ];
        }

        return $out;
    }

    /**
     * @param ReflectionClass<object> $class
     */
    private function findDeclaringTraitForProperty(ReflectionClass $class, string $propertyName): ?string
    {
        foreach ($class->getTraits() as $trait) {
            if ($trait->hasProperty($propertyName)) {
                return $trait->getName();
            }

            $nested = $this->findDeclaringTraitForProperty($trait, $propertyName);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    /**
     * Assert no circular dependencies in the full dependency graph (both readonly and execution-scoped).
     * Uses topological sort with cycle detection. Throws CircularDependencyException with full chain.
     */
    private function assertNoCycles(): void
    {
        // Build adjacency list from injection metadata
        $allClasses = array_unique(array_merge(
            array_values(array_filter($this->idToClass, fn($id) => !interface_exists($id), ARRAY_FILTER_USE_KEY)),
            array_keys($this->executionScopedClasses),
        ));

        $adjacency = [];
        foreach ($allClasses as $class) {
            $adjacency[$class] = [];
            $injections = $this->injections[$class] ?? [];
            foreach ($injections as $info) {
                if ($info['kind'] === 'factory') {
                    continue; // Factories don't participate in cycle detection
                }
                $target = $this->resolveToClass($info['type']);
                if ($target !== null && $target !== $class) {
                    $adjacency[$class][] = $target;
                }
            }
        }

        // DFS-based cycle detection
        $white = []; // unvisited
        $gray = [];  // in current path
        $black = []; // fully processed

        foreach (array_keys($adjacency) as $node) {
            $white[$node] = true;
        }

        foreach (array_keys($adjacency) as $node) {
            if (isset($white[$node])) {
                $this->dfsDetectCycle($node, $adjacency, $white, $gray, $black, []);
            }
        }
    }

    /**
     * @param array<string, list<string>> $adjacency
     * @param array<string, true> $white
     * @param array<string, true> $gray
     * @param array<string, true> $black
     * @param list<string> $path
     */
    private function dfsDetectCycle(
        string $node,
        array $adjacency,
        array &$white,
        array &$gray,
        array &$black,
        array $path,
    ): void {
        unset($white[$node]);
        $gray[$node] = true;
        $path[] = $node;

        foreach ($adjacency[$node] ?? [] as $neighbor) {
            if (isset($black[$neighbor])) {
                continue;
            }
            if (isset($gray[$neighbor])) {
                // Found a cycle — extract the cycle path
                $cycleStart = array_search($neighbor, $path, true);
                if ($cycleStart === false) {
                    throw new \LogicException("Cycle detection invariant violated: {$neighbor} is gray but missing from path.");
                }
                $chain = array_slice($path, $cycleStart);
                $chain[] = $neighbor;
                throw new CircularDependencyException(
                    chain: $chain,
                    message: 'Circular dependency detected: ' . implode(' -> ', $chain),
                );
            }
            if (isset($white[$neighbor])) {
                $this->dfsDetectCycle($neighbor, $adjacency, $white, $gray, $black, $path);
            }
        }

        unset($gray[$node]);
        $black[$node] = true;
    }

    private function buildReadonlyGraph(): void
    {
        $readonlyClasses = [];
        foreach ($this->idToClass as $id => $class) {
            if (isset($this->executionScopedClasses[$class])) {
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
        $order = $this->topologicalOrder(array_keys($readonlyClasses));
        foreach ($order as $class) {
            $instance = $this->createInstance($class);
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

    private function buildExecutionScopedPrototypes(): void
    {
        $order = $this->topologicalOrder(array_keys($this->executionScopedClasses));
        foreach ($order as $class) {
            $prototype = $this->createInstance($class);
            $this->executionScopedPrototypes[$class] = $prototype;
            $this->idToClass[$class] = $class;
            foreach ($this->idToClass as $id => $c) {
                if ($c === $class && $id !== $class) {
                    $this->idToClass[$id] = $class;
                }
            }
        }
    }

    /**
     * Create instance of a container-managed framework object.
     *
     * Uses newInstanceWithoutConstructor(). Constructors with parameters are forbidden.
     * Dependencies must use #[InjectAs*] and #[Config] property attributes.
     */
    private function createInstance(string $class): object
    {
        $ref = new ReflectionClass($class);

        $ctor = $ref->getConstructor();
        if ($ctor !== null && $ctor->getNumberOfParameters() > 0) {
            throw new InjectionException(
                targetClass: $class,
                propertyName: '__construct',
                propertyType: 'constructor',
                injectionKind: 'constructor',
                message: "Container-managed framework object {$class} must not have constructor parameters. "
                    . "Use #[InjectAsReadonly], #[InjectAsMutable], #[InjectAsFactory], or #[Config] properties instead.",
            );
        }

        try {
            $instance = $ref->newInstanceWithoutConstructor();
        } catch (\Throwable $e) {
            throw new \RuntimeException("Container: cannot instantiate {$class}: " . $e->getMessage(), 0, $e);
        }

        $this->injectConfigProperties($instance, $class, $ref);
        $this->injectPropertiesInto($instance, $class);
        return $instance;
    }

    /**
     * Resolve constructor params from container and create instance; then set InjectAs* properties.
     * Used for resolver classes (generated registry resolvers) that have constructor dependencies.
     *
     * @internal Resolvers are the only exception to the no-constructor rule — they are generated
     * classes that bridge the registry pattern and need constructor injection.
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
                    if ($param->isDefaultValueAvailable()) {
                        $args[] = $param->getDefaultValue();
                        continue;
                    }
                    throw new \RuntimeException("Container: cannot resolve constructor param \${$param->getName()} for {$class}");
                }
                $name = $type->getName();
                $inst = $this->readonlyInstances[$name] ?? $this->readonlyInstances[$this->idToClass[$name] ?? ''] ?? null
                    ?? $this->executionScopedPrototypes[$name] ?? $this->executionScopedPrototypes[$this->idToClass[$name] ?? ''] ?? null;
                if ($inst === null) {
                    if ($param->isDefaultValueAvailable()) {
                        $args[] = $param->getDefaultValue();
                        continue;
                    }
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
     * Inject #[Config] properties from environment variables or defaults.
     */
    private function injectConfigProperties(object $instance, string $class, ReflectionClass $ref): void
    {
        foreach ($ref->getProperties() as $prop) {
            $configAttrs = $prop->getAttributes(Config::class);
            if (empty($configAttrs)) {
                continue;
            }

            // Visibility already validated in collectInjections, but validate here too
            // for classes not yet collected (e.g. during prototype building)
            if (!$prop->isProtected()) {
                $visibility = $prop->isPrivate() ? 'private' : 'public';
                throw new InjectionException(
                    targetClass: $class,
                    propertyName: $prop->getName(),
                    propertyType: (string) $prop->getType(),
                    injectionKind: 'config',
                    message: "Cannot inject config into {$visibility} property {$class}::\${$prop->getName()}. "
                        . "Config properties must be protected.",
                );
            }

            $type = $prop->getType();
            if (!$type instanceof ReflectionNamedType) {
                throw new InjectionException(
                    targetClass: $class,
                    propertyName: $prop->getName(),
                    propertyType: (string) $type,
                    injectionKind: 'config',
                    message: "#[Config] property {$class}::\${$prop->getName()} must have a named scalar type "
                        . "(int, float, string, bool, or a backed enum), got: {$type}.",
                );
            }

            $typeName = $type->getName();

            // Arrays are forbidden
            if ($typeName === 'array') {
                throw new InjectionException(
                    targetClass: $class,
                    propertyName: $prop->getName(),
                    propertyType: 'array',
                    injectionKind: 'config',
                    message: "#[Config] property {$class}::\${$prop->getName()} must not be typed as array. "
                        . "Arrays have no schema and cannot be validated at boot. "
                        . "Use a typed DTO or collection object injected via #[InjectAsReadonly] instead.",
                );
            }

            // Must be a scalar builtin or a backed enum
            if ($type->isBuiltin() && !in_array($typeName, ['int', 'float', 'string', 'bool'], true)) {
                throw new InjectionException(
                    targetClass: $class,
                    propertyName: $prop->getName(),
                    propertyType: $typeName,
                    injectionKind: 'config',
                    message: "#[Config] property {$class}::\${$prop->getName()} has unsupported type: {$typeName}. "
                        . "Allowed types: int, float, string, bool, or a backed enum.",
                );
            }

            if (!$type->isBuiltin()) {
                // Non-builtin: must be a backed enum
                if (!class_exists($typeName) && !interface_exists($typeName)) {
                    throw new InjectionException(
                        targetClass: $class,
                        propertyName: $prop->getName(),
                        propertyType: $typeName,
                        injectionKind: 'config',
                        message: "#[Config] property {$class}::\${$prop->getName()} has unresolvable type: {$typeName}.",
                    );
                }
                $ref2 = new ReflectionClass($typeName);
                if (!$ref2->isEnum() || !$ref2->implementsInterface(\BackedEnum::class)) {
                    throw new InjectionException(
                        targetClass: $class,
                        propertyName: $prop->getName(),
                        propertyType: $typeName,
                        injectionKind: 'config',
                        message: "#[Config] property {$class}::\${$prop->getName()} has class type: {$typeName}. "
                            . "#[Config] only supports int, float, string, bool, and backed enums. "
                            . "For service dependencies, use #[InjectAsReadonly] instead.",
                    );
                }
            }

            /** @var Config $config */
            $config = $configAttrs[0]->newInstance();
            $value = $config->env !== null
                ? $this->resolveEnvValue($config->env, $config->default, $typeName)
                : $config->default;

            $prop->setAccessible(true);
            $prop->setValue($instance, $value);
        }
    }

    /**
     * Resolve an environment variable value, casting to the target type.
     */
    private function resolveEnvValue(string $envKey, int|float|string|bool|null $default, string $targetType): int|float|string|bool|null
    {
        $raw = Environment::getEnvValue($envKey);
        if ($raw === null) {
            return $default;
        }

        return match ($targetType) {
            'int' => (int) $raw,
            'float' => (float) $raw,
            'string' => $raw,
            'bool' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            default => $this->resolveEnvBackedEnum($raw, $targetType, $default),
        };
    }

    private function resolveEnvBackedEnum(string $raw, string $enumClass, mixed $default): mixed
    {
        if (!enum_exists($enumClass) || !is_subclass_of($enumClass, \BackedEnum::class)) {
            return $default;
        }
        return $enumClass::tryFrom($raw) ?? $default;
    }

    /**
     * Inject #[InjectAs*] properties with strict failure.
     * Every annotated property must resolve. No silent skip.
     */
    private function injectPropertiesInto(object $instance, string $class): void
    {
        $ref = new ReflectionClass($instance);
        $injections = $this->injections[$class] ?? [];

        foreach ($injections as $propName => $info) {
            $prop = $ref->getProperty($propName);
            $prop->setAccessible(true);
            $kind = $info['kind'];
            $typeName = $info['type'];

            $resolved = $this->resolveForInjection($kind, $typeName);

            if ($resolved !== null) {
                $prop->setValue($instance, $resolved);
                continue;
            }

            // For mutable properties that are execution-context types, skip during boot —
            // they will be resolved at execution time via injectMutableProperties()
            if ($kind === 'mutable' && $this->isExecutionContextType($typeName)) {
                continue;
            }

            // For mutable properties in execution-scoped classes, the prototype may not
            // need the value at build time — it will be injected after clone
            if ($kind === 'mutable' && isset($this->executionScopedClasses[$class])) {
                continue;
            }

            throw new InjectionException(
                targetClass: $class,
                propertyName: $propName,
                propertyType: $typeName,
                injectionKind: $kind,
                message: "Cannot inject {$class}::\${$propName} (type: {$typeName}, "
                    . "kind: {$kind}). No binding found.",
            );
        }
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

    private function resolveForInjection(string $kind, string $typeName): ?object
    {
        return match ($kind) {
            'factory' => $this->factories[$typeName] ?? null,
            'readonly' => $this->readonlyInstances[$typeName]
                ?? $this->readonlyInstances[$this->idToClass[$typeName] ?? '']
                ?? null,
            'mutable' => $this->executionScopedPrototypes[$typeName]
                ?? $this->executionScopedPrototypes[$this->idToClass[$typeName] ?? '']
                ?? $this->executionContextValues[$typeName]
                ?? null,
            default => throw new \InvalidArgumentException("Unknown injection kind in resolveForInjection(): {$kind}"),
        };
    }

    /**
     * Validate all bindings at boot time. Every annotated property must be resolvable.
     */
    private function validateAllBindings(): void
    {
        foreach ($this->injections as $class => $properties) {
            foreach ($properties as $propName => $info) {
                $kind = $info['kind'];
                $typeName = $info['type'];

                $resolved = $this->resolveForInjection($kind, $typeName);

                if ($resolved !== null) {
                    continue;
                }

                // Execution-context types are resolved per execution, not at boot
                if ($kind === 'mutable' && $this->isExecutionContextType($typeName)) {
                    continue;
                }

                // Mutable properties in execution-scoped classes may reference
                // execution-context values or other execution-scoped prototypes
                if ($kind === 'mutable' && isset($this->executionScopedClasses[$class])) {
                    // Check if it's an execution-scoped prototype type
                    $protoClass = $this->idToClass[$typeName] ?? $typeName;
                    if (isset($this->executionScopedPrototypes[$protoClass]) || isset($this->executionScopedPrototypes[$typeName])) {
                        continue;
                    }
                }

                throw new InjectionException(
                    targetClass: $class,
                    propertyName: $propName,
                    propertyType: $typeName,
                    injectionKind: $kind,
                    message: "Boot validation failed: {$class}::\${$propName} "
                        . "(type: {$typeName}, kind: {$kind}) has no binding.",
                );
            }
        }
    }

    private function isExecutionContextType(string $typeName): bool
    {
        return in_array($typeName, self::EXECUTION_CONTEXT_TYPES, true);
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

    /**
     * @param array<string> $classes
     * @return array<string>
     */
    private function topologicalOrder(array $classes): array
    {
        $dep = [];
        foreach ($classes as $c) {
            $dep[$c] = [];
            $injections = $this->injections[$c] ?? [];
            foreach ($injections as $info) {
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

    private function injectFactoriesIntoPrototypes(): void
    {
        foreach ($this->executionScopedPrototypes as $class => $instance) {
            $injections = $this->injections[$class] ?? [];
            foreach ($injections as $propName => $info) {
                if (($info['kind'] ?? '') !== 'factory') {
                    continue;
                }
                $factory = $this->factories[$info['type']] ?? null;
                if ($factory === null) {
                    continue;
                }
                $prop = (new ReflectionClass($instance))->getProperty($propName);
                $prop->setAccessible(true);
                $prop->setValue($instance, $factory);
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
                $defaultImpl = $this->readonlyInstances[$active] ?? $this->executionScopedPrototypes[$active] ?? null;
            }
            if ($defaultImpl === null) {
                continue;
            }
            $byKey = [];
            $enumKeys = [];
            $enumClass = null;
            foreach ($implementations as $impl) {
                $implClass = $impl['class'];
                $factoryKey = $impl['factoryKey'] ?? null;
                if (!$factoryKey instanceof \BackedEnum) {
                    throw new ContainerBuildException(
                        "Factory contract {$baseInterface} requires enum-backed factoryKey for every implementation. Missing on {$implClass}."
                    );
                }
                $currentEnumClass = $factoryKey::class;
                if ($enumClass === null) {
                    $enumClass = $currentEnumClass;
                } elseif ($enumClass !== $currentEnumClass) {
                    throw new ContainerBuildException(
                        "Factory contract {$baseInterface} mixes enum types {$enumClass} and {$currentEnumClass}."
                    );
                }
                $key = (string) $factoryKey->value;
                $inst = $this->readonlyInstances[$implClass] ?? $this->executionScopedPrototypes[$implClass] ?? null;
                if ($inst !== null) {
                    $byKey[$key] = $inst;
                    $enumKeys[$key] = $factoryKey;
                }
            }
            if ($enumClass === null || !enum_exists($enumClass)) {
                throw new ContainerBuildException("Factory contract {$baseInterface} has no enum key type.");
            }
            foreach ($enumClass::cases() as $case) {
                if (!isset($byKey[(string) $case->value])) {
                    throw new ContainerBuildException(
                        "Factory contract {$baseInterface} is missing implementation for {$enumClass}::{$case->name}."
                    );
                }
            }
            $this->factories[$factoryInterface] = new ContractFactory($defaultImpl, $byKey, $enumKeys);
        }
    }
}
