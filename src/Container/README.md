# Semitexa DI Container

Semitexa uses a **custom DI container** (no PHP-DI). It is built once per worker. A class enters the container through a class attribute, and receives its dependencies through property attributes. The canonical DI policy is `docs/workspace/DI_ONE_WAY.md` in the `semitexa/docs` package (`vendor/semitexa/docs/docs/workspace/DI_ONE_WAY.md` when installed); container mechanics (what gets registered, how resolution works, execution scoping) are documented here.

## Who gets into the container

- **Services:** a class marked **#[AsService]** is registered under its own class name, one instance per worker. No interface is needed.
- **Service contracts:** an implementation marked **#[SatisfiesServiceContract(of: SomeInterface::class)]** (or **#[SatisfiesRepositoryContract(of: ...)]** for a repository) is registered under the interface. The container reads them through `ServiceContractRegistry` (the same data `bin/semitexa contracts:list` prints). When several modules implement one interface, the registry alone picks the active one: a module that `extends` another (`extra.semitexa-module.extends` in its `composer.json`) wins over it, a project module wins over a package, and any module wins over Core. Between modules nothing relates, the one found first wins. `contracts:list` names what decided each contract (`decided_by`, `tied_with`), and `lint:di` warns about a tie between two project modules. Classes in `Semitexa\Core\` are treated as module `Core`.
- **Handlers:** are **not** service contracts. They are discovered via **#[AsPayloadHandler(payload: ..., resource: ...)]** and registered automatically so the kernel can resolve them by concrete class when handling a route. Implement `TypedHandlerInterface`; do not put `#[SatisfiesServiceContract]` on handlers.
- **Event and pipeline listeners:** are discovered via **#[AsEventListener(event: ...)]** and **#[AsPipelineListener]** on the listener class; no marker interface is involved.
- **Classes a package declares:** an attribute that implements `ImpliesService` registers its carriers like `#[AsService]`. ORM repositories (`#[AsRepository]`), auth handlers and slot handlers are registered when their packages are installed.

Bootstrap entries (e.g. `Environment`) are registered in `ContainerFactory::registerBootstrapEntries()` with `$container->set()` before `build()`.

## How to inject (property injection is the One Way)

Dependencies flow into container-managed classes **only via protected properties**, each annotated with exactly one of these attributes:

| Attribute            | Meaning |
|----------------------|--------|
| **#[InjectAsReadonly]** | Shared instance per worker (same for all requests). |
| **#[InjectAsMutable]**  | A value of the current execution: the Request, Session, CookieJar, tenant, auth or locale context, or a fresh clone of an execution-scoped service. Filled on every clone of an execution-scoped class. |
| **#[InjectAsFactory]**  | Factory for a contract: `getDefault()`, `get(<Enum> $key)`, `keys()`. Property type must be a Factory* interface. |

- **What** is injected is determined by the **property type** (the type hint).
- Only **protected** properties are considered.
- `#[InjectAsReadonly(optional: true)]` and `#[InjectAsMutable(optional: true)]` leave the property uninitialized when nothing is bound; guard reads with `isset()`.
- `#[Config]` on a protected property injects a configuration value; it is not a service injection.
- The container instantiates container-managed classes via `newInstanceWithoutConstructor()`, so the constructor is never used as a DI channel. Declaring `__construct` with parameters on a container-managed class is rejected by both `InjectionViaConstructorRule` (PHPStan) and the runtime container.

### “No constructor injection” ≠ “no constructors”

This rule bans one specific use of the constructor — as a DI entry point on container-managed classes — and nothing else.

- **Allowed on container-managed classes:** a parameterless `__construct` used for local initialization, default setup, or invariant wiring. The container does not call it, but declaring it is fine.
- **Allowed on non-container-managed classes:** constructors with parameters on DTOs, payloads, resources, value objects, events, context objects, domain model entities — the DI rule does not apply to these.
- **After injection:** implement `InitializesAfterInjectionInterface` and the container calls `initialize()` once every injected property is set.
- **Not allowed:** `__construct($dep1, $dep2)` on a class marked with `#[AsService]`, `#[AsPayloadHandler]`, `#[AsEventListener]`, `#[AsPipelineListener]`, `#[SatisfiesServiceContract]`, `#[SatisfiesRepositoryContract]`, or `#[AsRepository]`. Move those dependencies onto protected properties with the injection attribute that matches the scope.

Generated `Factory*` classes are the one internal exception: the container constructs them around its generic factory for the contract, not via property injection. Application code should not reproduce this pattern.

Example (a handler; it is execution-scoped, so it may use all three attributes):

```php
#[AsPayloadHandler(payload: MyPayload::class, resource: MyResource::class)]
final class MyHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected LoggerInterface $logger;

    #[InjectAsMutable]
    protected ItemListProviderInterface $provider;

    #[InjectAsFactory]
    protected FactoryItemListProviderInterface $providerFactory;

    // Values of the current execution are #[InjectAsMutable] too.
    // A typed property with no attribute receives nothing.
    #[InjectAsMutable]
    protected Request $httpRequest;

    #[InjectAsMutable]
    protected SessionInterface $session;

    #[InjectAsMutable]
    protected CookieJarInterface $cookies;

    public function handle(MyPayload $payload, MyResource $resource): MyResource
    {
        // ...
    }
}
```

## Scopes

Every container-managed class has one of two scopes:

- **Worker-scoped (the default):** `#[AsService]`, contract implementations, repositories. One instance per worker, built at boot and shared by every request. It takes its dependencies with `#[InjectAsReadonly]` and `#[InjectAsFactory]`.
- **Execution-scoped:** a class marked **#[ExecutionScoped]**, and every `#[AsPayloadHandler]`, `#[AsEventListener]` and `#[AsPipelineListener]` (auth and slot handlers too). The container builds one prototype at boot; each `get()` returns `clone(prototype)` with its `#[InjectAsMutable]` properties filled from the current execution.

Two combinations fail the container build, each with a message that names the class and the property:

- `#[InjectAsMutable]` on a worker-scoped class: the property would never be filled.
- A worker-scoped class taking an execution-scoped service as a property: the shared instance would capture one execution's clone.

The fix is the same for both, and it changes both the class and the property: mark the consuming class `#[ExecutionScoped]` and take the property with `#[InjectAsMutable]`; or keep the class worker-scoped, inject a worker-scoped service with `#[InjectAsReadonly]` and read the per-execution value from it at call time. Marking the class `#[ExecutionScoped]` while the property stays `#[InjectAsReadonly]` still fails the build.

## Lifecycle

- **Build (once per worker):** every class listed under "Who gets into the container" is discovered; the dependency graph is built from properties only; worker-scoped instances and execution-scoped prototypes are created, then validated. A property that cannot be resolved fails the build. A dependency cycle (factories aside) is rejected with its full chain.
- **Worker-scoped:** one instance per type per worker; stored after build. After boot the container is sealed: `set()` throws.
- **Execution-scoped:** one prototype per type; each `get()` clones it and fills its `#[InjectAsMutable]` and `#[InjectAsFactory]` properties. `#[InjectAsReadonly]` properties are copied with the clone and not resolved again.

The values of one execution (`Request`, `SessionInterface`, `CookieJarInterface`, `TenantContextInterface`, `AuthContextInterface`, `LocaleContextInterface`) are **not** part of the dependency graph. The framework passes them to the container as an `ExecutionContext`, stored per coroutine, so concurrent requests on one worker never see each other's values.

## Usage

Application code does not call the container: it declares properties. `ContainerFactory` is `@internal`, for framework bootstrap only:

```php
use Semitexa\Core\Container\ContainerFactory;

$container = ContainerFactory::get();                    // the worker's container
$requestScoped = ContainerFactory::createRequestScoped(); // one per request
$handler = $requestScoped->get(MyHandler::class);        // execution-scoped: clone + current execution
```

A class the container does not manage (a payload, a resource, a component) may take `ContainerInterface` with `#[InjectAsReadonly]` and read an execution-scoped service at the point of use: `RequestScopedContainer::forCurrentExecution($this->container)->get(Foo::class)`.

## Factory*

- **No resolver:** the container asks no `App\Registry\Contracts\{InterfaceShortName}Resolver` for the active implementation; the registry alone decides it (module order, above). `bin/semitexa registry:sync:contracts` removes the resolvers earlier versions generated. To change the winner, declare `extends`; to choose at runtime, use a Factory* interface.
- **Factory*:** For contracts with a Factory* interface (e.g. `FactoryItemListProviderInterface`, in the namespace of the contract), the container binds it either to a generated factory class (`App\Registry\Contracts\{Name}Factory`, when present) or to a generic `ContractFactory` implementation. Each implementation names its key with `#[SatisfiesServiceContract(of: ..., factoryKey: SomeEnum::Case)]`. Inject the Factory* interface to use `getDefault()`, `get($key)`, `keys()`.

See `docs/en/di/` in the `semitexa/docs` package for contracts, `contracts:list`, and factory conventions.

## Swoole

- Container is built once per worker.
- `RequestScopedContainer::reset()` is called after each request (`Application`, `SwooleBootstrap`) to clear the request-scoped cache and the execution context; the main container is not rebuilt.
- Worker-scoped services are safe to keep as long as they hold no per-request state; execution-scoped services are cloned per execution and receive its values, so nothing leaks between requests.
