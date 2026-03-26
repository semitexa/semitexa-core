# Events and Pipeline

Semitexa has three lifecycle extension systems that serve different purposes:

## 1. Pipeline Events (request lifecycle)

The request pipeline is a fixed sequence of phases: **Auth → Access → Handle**. Pipeline listeners are always **synchronous** — the HTTP response depends on their result.

| Phase | Event Class | Purpose |
|-------|------------|---------|
| Auth | `Pipeline\AuthCheck` | Runs auth handlers via `AuthBootstrapper`. Checks `#[RequiresAuth]` on request DTOs. |
| Access | `Pipeline\AccessCheck` | Reads `#[RequiresAbility]` from request DTO, calls `Gate::authorize()`. |
| Handle | `Pipeline\HandleRequest` | Runs route-specific handlers (PayloadHandler) and any registered pipeline listeners. |

Pipeline listeners use `#[AsPipelineListener(phase: AuthCheck::class, priority: 0)]` and implement `PipelineListenerInterface`.

Short-circuit via exceptions: `AuthenticationRequiredException` → 401, `AccessDeniedException` → 403.

## 2. Server Lifecycle Hooks (Swoole server events)

Server lifecycle hooks are for Swoole server-managed events such as `PreStart`, `WorkerStart`, `WorkerStop`, `WorkerError`, `Start`, and `Shutdown`. They are not business events and they are not request pipeline listeners.

Use `#[AsServerLifecycleListener(phase: ..., priority: ...)]` on a class that implements the dedicated server lifecycle listener contract.

Typical use cases:

- package bootstrap during `WorkerStart`
- pre-fork shared resource creation during `PreStart`
- asset registry boot
- server/table wiring for Swoole-specific package infrastructure
- worker-local cache warmup
- cleanup or flush logic on `WorkerStop`
- diagnostics and telemetry on `WorkerError`
- server-level startup or shutdown integration in non-request code

Rules:

- do not put package-specific bootstrap logic directly into `SwooleBootstrap`
- use `PreStart` for resources that must exist before `Server::start()` forks workers
- do not use domain events for pre-container or worker bootstrap concerns
- do not use lifecycle hooks for request-scoped logic
- keep lifecycle listeners idempotent and boot-safe

Recommended placement:

- framework packages: `src/Server/Lifecycle/`
- project modules: `Application/Server/`

## 3. Domain Events (business side-effects)

Domain events are for side-effects triggered after business operations. They support sync, async (Swoole defer), and queued (RabbitMQ) execution.

Domain listeners use `#[AsEventListener(event: EventClass::class, execution: ...)]` and live in `Event/DomainListener/`.

### Configuration

- **EVENTS_ASYNC** (`.env`): `0` (default) = in-memory (sync); `1` (or `true`/`yes`) = use RabbitMQ for async.
- **EVENTS_TRANSPORT**: Override transport: `in-memory` or `rabbitmq`.
- **EVENTS_QUEUE_DEFAULT**: Default queue name pattern when not set per handler.

When **EVENTS_ASYNC=1**, the app uses RabbitMQ. If you run with Docker, `bin/semitexa server:start` automatically uses `docker-compose.rabbitmq.yml` (so RabbitMQ is started and the app connects to the `rabbitmq` service).

### Sync vs async per handler

Every declared handler (e.g. `#[AsPayloadHandler(...)]`) has an **execution** option:

- **Sync** (default): handler runs in the same process and blocks the HTTP response until it finishes.
- **Async**: handler is enqueued; the HTTP response is sent immediately and the handler runs later.

```php
#[AsPayloadHandler(payload: SomeRequest::class, resource: SomeResponse::class, execution: HandlerExecution::Async)]
```

### HandlerCompleted event

`HandlerCompleted` is a **domain event** dispatched once after the entire HandleRequest phase completes. It is not a pipeline phase — it is a side-effect for domain listeners (SSE push, analytics, etc.).

### Running the worker (async events)

```bash
bin/semitexa queue:work
```

## Comparison

| | Pipeline Events | Server Lifecycle Hooks | Domain Events |
|---|---|---|
| Registry | `PipelineListenerRegistry` | `ServerLifecycleRegistry` | `EventListenerRegistry` |
| Attribute | `#[AsPipelineListener]` | `#[AsServerLifecycleListener]` | `#[AsEventListener]` |
| Execution | Always sync (in request) | Always sync (bootstrap path) | Sync / Async / Queued |
| Dispatcher / Invoker | `PipelineExecutor` | `ServerLifecycleInvoker` | `EventDispatcher` |
| Purpose | Request lifecycle phases | Swoole server lifecycle events | Business logic side-effects |
| Location | `Event/System/` | `src/Server/Lifecycle/` or module `Application/Server/` | `Event/DomainListener/` |
