# Module structure: Payloads and Handlers

This is the canonical Semitexa module layout for request payloads, handlers, and response DTOs.

---

## Payload: `Application/Payload/{Type}/`

| Subfolder | Purpose | Attribute / usage |
|-----------|---------|-------------------|
| **Request** | HTTP request DTOs (route + methods) | `#[AsPayload(path, methods, responseWith)]`; require entry in `src/registry/Payloads/` |
| **Session** | Session segment DTOs | `#[SessionSegment('name')]`; `SessionInterface::getPayload()` / `setPayload()` |
| **Event** | Event DTOs for dispatch | Used with `EventDispatcher::create(EventClass::class, [...])` and `dispatch()` |

**Namespaces:** `Semitexa\Modules\{Module}\Application\Payload\Request\`, `...\Payload\Session\`, `...\Payload\Event\`.

Do **not** put these in `Application/Session/` or other ad-hoc module-root folders. Use **`Application/Payload/Request/`**, **`Payload/Session/`**, **`Payload/Event/`** only.

Request DTOs can declare pipeline requirements: `#[RequiresAuth]`, `#[RequiresAbility('ability')]`.

---

## Handler: `Application/Handler/{Type}/`

| Subfolder | Purpose | Attribute |
|-----------|---------|-----------|
| **PayloadHandler** | HTTP handlers (payload → resource) | `#[AsPayloadHandler(payload: ..., resource: ...)]` |
| **System** | Pipeline listeners (Auth/Access phases) | `#[AsPipelineListener(phase: ..., priority: ...)]` |
| **Server** | Swoole server lifecycle hooks including pre-fork bootstrap | `#[AsServerLifecycleListener(phase: ..., priority: ...)]` |
| **DomainListener** | Domain event listeners (sync/async/queued) | `#[AsEventListener(event: ..., execution: ...)]` |

---

## Full layout

```
Application/
├── Payload/
│   ├── Request/          # HTTP request DTOs
│   ├── Session/          # Session segment DTOs
│   └── Event/            # Event DTOs
├── Handler/
│   ├── PayloadHandler/   # HTTP handlers
│   ├── System/           # Pipeline listeners
│   └── DomainListener/   # Domain event listeners
├── Server/               # Swoole server lifecycle listeners
├── Resource/             # Response DTOs
├── View/templates/
└── Service/              # optional
```

---

See **ADDING_ROUTES.md** for adding new routes. Project-level docs may extend this with app-specific conventions, but package and project docs should keep the same folder names.
