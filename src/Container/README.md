# Semitexa DI Container

Semitexa uses a **custom DI container** (no PHP-DI). It is built once per worker. There is one way to register services (contract attributes) and one way to inject (property attributes). The canonical DI policy is documented in `docs/DI_ONE_WAY.md` inside this package (or `vendor/semitexa/docs/workspace/DI_ONE_WAY.md` when installed); container mechanics (what gets registered, how resolution works, request scoping) are documented here.

## Who gets into the container

- **Service contracts:** types registered via **#[AsServiceContract(of: SomeInterface::class)]** on implementation classes. The container discovers them through `ServiceContractRegistry` (same as `bin/semitexa contracts:list`).
- **Handlers:** are **not** service contracts. They are discovered via **#[AsPayloadHandler(payload: ..., resource: ...)]** and registered automatically so the kernel can resolve them by concrete class when handling a route. Implement `TypedHandlerInterface`; do not use `AsServiceContract` on handlers.
- **Event listeners:** implement `EventListenerInterface` and use `#[AsServiceContract(of: EventListenerInterface::class)]`.
- **Other services:** define an interface and put `#[AsServiceContract(of: ThatInterface::class)]` on the implementation(s). Classes in `Semitexa\Core\` are treated as module `Core`.

Bootstrap entries (e.g. `Environment`) are registered in `ContainerFactory::registerBootstrapEntries()` with `$container->set()` before `build()`.

## How to inject (property injection is the One Way)

Dependencies flow into container-managed classes **only via protected properties**, each annotated with exactly one of these attributes:

| Attribute            | Meaning |
|----------------------|--------|
| **#[InjectAsReadonly]** | Shared instance per worker (same for all requests). |
| **#[InjectAsMutable]**  | New clone per `get()`; then `RequestContext` (Request, Session, CookieJar) is injected into the clone. |
| **#[InjectAsFactory]**  | Factory for a contract: `getDefault()`, `get(string $key)`, `keys()`. Property type must be a Factory* interface. |

- **What** is injected is determined by the **property type** (the type hint).
- Only **protected** properties are considered.
- The container instantiates container-managed classes via `newInstanceWithoutConstructor()`, so the constructor is never used as a DI channel. Declaring `__construct` with parameters on a container-managed class is rejected by both `InjectionViaConstructorRule` (PHPStan) and the runtime container.

### “No constructor injection” ≠ “no constructors”

This rule bans one specific use of the constructor — as a DI entry point on container-managed classes — and nothing else.

- **Allowed on container-managed classes:** a parameterless `__construct` used for local initialization, default setup, or invariant wiring. The container does not call it, but declaring it is fine.
- **Allowed on non-container-managed classes:** constructors with parameters on DTOs, payloads, resources, value objects, events, context objects, domain model entities — the DI rule does not apply to these.
- **Not allowed:** `__construct($dep1, $dep2)` on a class marked with `#[AsService]`, `#[AsPayloadHandler]`, `#[AsEventListener]`, `#[AsPipelineListener]`, `#[SatisfiesServiceContract]`, `#[SatisfiesRepositoryContract]`, or `#[AsRepository]`. Move those dependencies onto protected properties with the injection attribute that matches the scope.

Generated resolvers are the one internal exception: the container instantiates them with their implementation list, not via property injection. Application code should not reproduce this pattern.

Example (handler; no AsServiceContract):

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

    // Request/Session/Cookie: no attribute; injected from RequestContext into mutable clones
    protected Request $httpRequest;
    protected SessionInterface $session;
    protected CookieJarInterface $cookies;

    public function handle(MyPayload $payload, MyResource $resource): MyResource
    {
        // ...
    }
}
```

## Lifecycle

- **Build (once per worker):** All `AsServiceContract` classes are discovered; dependency graph is built from properties only; readonly instances and mutable prototypes are created. Mutable↔mutable cycles are forbidden and cause an exception.
- **Readonly:** One instance per type per worker; stored after build.
- **Mutable:** One prototype per type; each `get()` returns `clone(prototype)` then injects the current **RequestContext** (Request, Session, CookieJar) into the clone. No re-resolution of other dependencies on clone.

Request/Session/Cookie are **not** part of the normal dependency graph; they are provided per request via `RequestContext`. The application sets them on `RequestScopedContainer`; when all three are set, the container’s `RequestContext` is updated so that mutable services receive them after clone.

## Usage

```php
use Semitexa\Core\Container\ContainerFactory;

$container = ContainerFactory::get();
$service = $container->get(SomeInterface::class);  // or concrete class

$requestScoped = ContainerFactory::getRequestScoped();
$handler = $requestScoped->get(MyHandler::class);   // mutable: clone + RequestContext
```

## Resolvers and Factory*

- **Resolver (optional):** If `App\Registry\Contracts\{InterfaceShortName}Resolver` exists, the container uses it to get the active implementation; otherwise it uses the active implementation from the registry (module order).
- **Factory*:** For contracts with a Factory* interface (e.g. `FactoryItemListProviderInterface`), the container binds it either to a generated factory class (when present) or to a generic `ContractFactory` implementation. Inject the Factory* interface to use `getDefault()`, `get($key)`, `keys()`.

See **docs/SERVICE_CONTRACTS.md** for contracts, `contracts:list`, and resolver/factory conventions.

## Swoole

- Container is built once per worker.
- `RequestScopedContainer::reset()` is called after each request (e.g. in `server.php`) to clear request-scoped values and tenant context; the main container is not rebuilt.
- Readonly services are safe to keep; mutable services are cloned per request and receive the current RequestContext, so no leakage between requests.
