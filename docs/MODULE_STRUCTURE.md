# Module structure: Payloads and Event Handlers

All DTOs (payloads) and event-driven handlers in a Semitexa module use a **single Payload folder with subfolders by type**.

---

## Payload: `Application/Payload/{Type}/`

| Subfolder | Purpose | Attribute / usage |
|-----------|---------|-------------------|
| **Request** | HTTP request DTOs (route + methods) | `#[AsPayload(path, methods, responseWith)]`; require entry in `src/registry/Payloads/` |
| **Session** | Session segment DTOs | `#[SessionSegment('name')]`; `SessionInterface::getPayload()` / `setPayload()` |
| **Event** | Event DTOs for dispatch | Used with `EventDispatcher::create(EventClass::class, [...])` and `dispatch()` |

**Namespaces:** `Semitexa\Modules\{Module}\Application\Payload\Request\`, `...\Payload\Session\`, `...\Payload\Event\`.

Do **not** put these in `Application/Session/` or `Application/Handler/` at module root — use **`Application/Payload/Request/`**, **`Payload/Session/`**, **`Payload/Event/`** only.

Request DTOs can declare pipeline requirements: `#[RequiresAuth]`, `#[RequiresAbility('ability')]`.

---

## Handler: `Application/Handler/{Type}/`

| Subfolder | Purpose | Attribute |
|-----------|---------|-----------|
| **PayloadHandler** | HTTP handlers (payload → resource) | `#[AsPayloadHandler(payload: ..., resource: ...)]` |
| **System** | Pipeline listeners (Auth/Access phases) | `#[AsPipelineListener(phase: ..., priority: ...)]` |
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
├── Resource/             # Response DTOs
├── View/templates/
└── Service/              # optional
```

---

See **ADDING_ROUTES.md** for adding new routes; project **docs/MODULE_STRUCTURE.md** for the full layout with pipeline docs.
