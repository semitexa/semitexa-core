# Request handlers (AsPayloadHandler + HandlerInterface)

## Description

Request handlers are classes that process a specific Request (Payload). They are marked with **#[AsPayloadHandler(payload: ..., resource: ...)]** and must **implement HandlerInterface**. Do **not** use `#[AsServiceContract]` on handlers — they are discovered by the kernel and invoked automatically; they are not service contracts. The framework discovers them in **modules** and invokes them to handle the corresponding route.

**Placement:** Handler classes must live in **modules** (`src/modules/`, `packages/`, or `vendor/`).  
Classes in project `src/` (namespace `App\`) are **not discovered** for routes — do not put new routes there. See [ADDING_ROUTES.md](../ADDING_ROUTES.md).

**DI:** Handlers are **mutable** services. Dependencies are injected via **protected** properties with **#[InjectAsReadonly]**, **#[InjectAsMutable]**, or **#[InjectAsFactory]** — not constructor injection. Session, CookieJar, and Request are filled from **RequestContext** on the handler clone. See [Container README](../src/Container/README.md).

## Usage

```php
use Semitexa\Core\Attributes\AsPayloadHandler;
use Semitexa\Core\Contract\HandlerInterface;
use Semitexa\Core\Contract\RequestInterface;
use Semitexa\Core\Contract\ResponseInterface;

#[AsPayloadHandler(payload: UserListRequest::class, resource: UserListResource::class)]
class UserListHandler implements HandlerInterface
{
    public function handle(RequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Handler logic
        return $response;
    }
}
```

## AsPayloadHandler parameters

### Required

- `payload` (string) - Request/Payload class that this handler processes.
- `resource` (string|null) - Resource class for the response (can be null for JSON-only handlers).

### Optional

- `execution` (HandlerExecution|string|null) - Execution mode:
  - `'sync'` or `HandlerExecution::Sync` - synchronous execution (default).
  - `'async'` or `HandlerExecution::Async` - asynchronous execution via queue.
- `transport` (string|null) - Queue transport name (required for async):
  - `'memory'` - In-memory queue (for testing).
  - `'rabbitmq'` - RabbitMQ queue.
- `queue` (string|null) - Queue name (default: handler class name).
- `priority` (int|null) - Handler priority (higher = executed earlier, default: 0).

## Synchronous Handlers

Synchronous handlers are executed immediately during request processing:

```php
use Semitexa\Core\Attributes\AsPayloadHandler;
use Semitexa\Core\Attributes\InjectAsReadonly;
use Semitexa\Core\Contract\HandlerInterface;

#[AsPayloadHandler(payload: DashboardRequest::class, resource: DashboardResource::class, execution: 'sync')]
class DashboardHandler implements HandlerInterface
{
    #[InjectAsReadonly]
    protected UserRepository $userRepository;

    public function handle(RequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        /** @var DashboardRequest $request */
        $user = $this->userRepository->findCurrentUser();
        $response->setContext(['user' => $user]);
        return $response;
    }
}
```

## Asynchronous Handlers

Asynchronous handlers are executed via a queue:

```php
use Semitexa\Core\Attributes\AsPayloadHandler;
use Semitexa\Core\Attributes\InjectAsReadonly;
use Semitexa\Core\Contract\HandlerInterface;
use Semitexa\Core\Queue\HandlerExecution;

#[AsPayloadHandler(
    payload: EmailSendRequest::class,
    resource: null,
    execution: HandlerExecution::Async,
    transport: 'rabbitmq',
    queue: 'emails',
    priority: 10
)]
class EmailSendHandler implements HandlerInterface
{
    #[InjectAsReadonly]
    protected EmailServiceInterface $emailService;

    public function handle(RequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // This code will run asynchronously in a worker process
        $this->emailService->send($request->email, $request->subject);
        return $response;
    }
}
```

## Handler Priorities

If there are multiple handlers for the same Request, they are executed in priority order:

```php
#[AsPayloadHandler(payload: UserRequest::class, resource: null, priority: 10)]
class UserValidationHandler implements HandlerInterface { ... }

#[AsPayloadHandler(payload: UserRequest::class, resource: null, priority: 5)]
class UserLoggingHandler implements HandlerInterface { ... }

#[AsPayloadHandler(payload: UserRequest::class, resource: UserResource::class)]
class UserProcessingHandler implements HandlerInterface { ... }
```

## Dependency Injection

Handlers get dependencies via **property injection** only (no constructor injection). Use **#[InjectAsReadonly]** for shared services, **#[InjectAsMutable]** for request-scoped clones, **#[InjectAsFactory]** for a factory of a contract. Session, CookieJar, and Request are **not** in the DI graph; the container sets them on the handler clone from **RequestContext** (see [Container README](../src/Container/README.md) and [SESSIONS_AND_COOKIES.md](../SESSIONS_AND_COOKIES.md)).

```php
use Semitexa\Core\Attributes\AsPayloadHandler;
use Semitexa\Core\Attributes\InjectAsReadonly;
use Semitexa\Core\Contract\HandlerInterface;

#[AsPayloadHandler(payload: UserListRequest::class, resource: UserListResource::class)]
class UserListHandler implements HandlerInterface
{
    #[InjectAsReadonly]
    protected UserRepository $userRepository;
    #[InjectAsReadonly]
    protected AuthService $authService;
    #[InjectAsReadonly]
    protected LoggerInterface $logger;

    public function handle(RequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->logger->info('Processing user list request');
        $users = $this->userRepository->findAll();
        $response->setContext(['users' => $users]);
        return $response;
    }
}
```

## Requirements

1. Class MUST implement **HandlerInterface** and be marked with **#[AsPayloadHandler(payload: ..., resource: ...)]** (do not use AsServiceContract on handlers).
2. Class MUST be marked with **#[AsPayloadHandler(payload: ..., resource: ...)]** so the route is registered.
3. `handle()` MUST accept `RequestInterface` and `ResponseInterface` and return `ResponseInterface`.
4. The `payload` parameter MUST point to a Request/Payload class (e.g. with `#[AsPayload]`).
5. For async handlers the `transport` parameter is required.

## Related attributes

- `#[AsPayload]` - Request/Payload DTO processed by the handler.
- `#[AsResource]` - Resource used to build the response.
- [Container README](../../src/Container/README.md) - DI rules, InjectAsReadonly/Mutable/Factory, RequestContext.

## See also

- [AsRequest](AsRequest.md) - Request DTOs.
- [AsResponse](AsResponse.md) - Response DTOs.
- [SERVICE_CONTRACTS.md](../SERVICE_CONTRACTS.md) - Service contracts and active implementation.
- Queue system: `packages/semitexa/core/src/Queue/README.md` (if available).
