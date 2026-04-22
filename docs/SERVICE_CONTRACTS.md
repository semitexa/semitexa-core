# Service contracts and active implementation

Service contracts are interfaces bound to implementations in the **Semitexa DI container**. Only types discovered via **#[AsServiceContract(of: SomeInterface::class)]** (and bootstrap entries like `Environment`) are in the container. Dependency injection is **only via protected properties** with **#[InjectAsReadonly]**, **#[InjectAsMutable]**, or **#[InjectAsFactory]** — see **src/Container/README.md** for full rules.

When several modules provide an implementation for the same interface, the **active** one is chosen by module "extends" order (child module wins).

## Seeing which contract is bound to which class

**Command (for developers and AI agents):**

```bash
bin/semitexa contracts:list
```

This prints a table: for each **contract (interface)** you see all **implementations** (module → class) and which one is **active** (marked).

**Machine-readable output (for AI agents and scripts):**

```bash
bin/semitexa contracts:list --json
```

Output is JSON: `contracts[]` with `contract`, `active`, and `implementations` (each with `module` and `class`). Use this when debugging "which class is injected for interface X" or when generating/checking bindings.

## When to use

- Debugging: "Which implementation of ItemListProviderInterface is actually used?"
- After adding or removing a module: confirm the active implementation is the one you expect.
- AI agents: before changing a contract or adding an override, run `contracts:list` or `contracts:list --json` to see current bindings.

## How it works

- Implementations are discovered via **#[AsServiceContract(of: SomeInterface::class)]** on classes (in modules).
- **Single implementation:** the container binds the interface directly to that class.
- **Multiple implementations:** a **registry resolver** is generated in `src/registry/Contracts/` (e.g. `ItemListProviderResolver`). The resolver receives all implementations via constructor and exposes `getContract()`; the container uses it to obtain the chosen implementation. By default the resolver returns the implementation chosen by module "extends" order; you can edit `getContract()` to pick another. Resolver is **optional**: if the class does not exist, the container uses the registry’s active implementation directly.
- **Generate resolvers:** run **`bin/semitexa registry:sync:contracts`** (or **`bin/semitexa registry:sync`** to sync payloads and contracts together). Only interfaces with **2+ implementations** get a resolver; single-implementation contracts are not generated. The container discovers resolvers by convention (`App\Registry\Contracts\{InterfaceShortName}Resolver`), no manifest needed.
- See `ServiceContractRegistry`, `RegistryContractResolverGenerator`, and `ModuleRegistry::getModuleOrderByExtends()` in the core package.

## Resolver as factory

The registry resolver is a **factory** in the usual sense: it receives all implementations via DI and exposes one method (`getContract()`) that returns the chosen instance. By default the generated code returns the implementation selected by module order, but you own the class and can change the logic:

- **Config-driven:** read a config key (e.g. `app.send_email.driver`) and return the implementation that matches.
- **Context-driven:** use request, tenant, or feature flags to pick an implementation.
- **Custom strategy:** merge, delegate, or switch between implementations inside `getContract()`.

There is no separate “Factory” pattern in Semitexa: service contracts with multiple implementations use this single mechanism. Document or name the resolver as a factory in your codebase if that helps (e.g. `SendEmailFactory` / `ItemListProviderResolver`).

## Factory* naming convention (choose implementation by key)

When you need to **choose** an implementation at runtime (e.g. by module name) instead of always using the active one, define an interface whose short name **starts with `Factory`** in the same namespace as the base contract.

- **Example:** For `ItemListProviderInterface`, define `FactoryItemListProviderInterface` extending `Semitexa\Core\Contract\ContractFactoryInterface`, with `getDefault()`, `get(string $key)`, and `keys()` returning the base contract type.
- **Keys** are composite: `Module::ShortClassName` (e.g. `Website::WebsiteItemListProvider`). This allows multiple implementations per module. Lookup in `get($key)` is **case-insensitive** (e.g. `website::websiteitemlistprovider` is the same). Run **`bin/semitexa registry:sync:contracts`** to generate the implementation in `src/registry/Contracts/{ContractShortName}Factory.php`.
- **Usage:** Inject the Factory* interface where you need to pick; use `getDefault()` for the active implementation or `get('Website::WebsiteItemListProvider')` for a specific one.

See `Semitexa\Core\Contract\ContractFactoryInterface` for the base interface.
