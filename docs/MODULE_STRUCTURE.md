# Module structure: Payloads and Handlers

All DTOs (payloads) and handlers in a Semitexa module use a **single Payload folder with subfolders by type**, like Handlers.

---

## Payload: `Application/Payload/{Type}/`

| Subfolder | Purpose | Attribute / usage |
|-----------|---------|-------------------|
| **Request** | HTTP request DTOs (route + methods) | `#[AsPayload(path, methods, responseWith)]`; require entry in `src/registry/Payloads/` |
| **Session** | Session segment DTOs | `#[SessionSegment('name')]`; `SessionInterface::getPayload()` / `setPayload()` |
| **Event** | Event DTOs for dispatch | Used with `EventDispatcher::create(EventClass::class, [...])` and `dispatch()` |

**Namespaces:** `Semitexa\Modules\{Module}\Application\Payload\Request\`, `...\Payload\Session\`, `...\Payload\Event\`.

Do **not** put these in `Application/Session/` or `Application/Event/` at module root — use **`Application/Payload/Request/`**, **`Payload/Session/`**, **`Payload/Event/`** only.

---

## Handlers: `Application/Handler/{Type}/`

| Subfolder | Purpose |
|-----------|---------|
| **Request** | HTTP handlers: `#[AsPayloadHandler(payload: ..., resource: ...)]` |
| **Event** | Event listeners: `#[AsEventListener(event: ..., execution: ...)]` |

---

## Full layout

```
Application/
├── Payload/
│   ├── Request/   # HTTP request DTOs
│   ├── Session/   # Session segment DTOs
│   └── Event/     # Event DTOs
├── Resource/      # Response DTOs
├── Handler/
│   ├── Request/   # HTTP handlers
│   └── Event/     # Event listeners
├── View/templates/
└── Service/       # optional
```

---

See **ADDING_ROUTES.md** for adding new routes; project **docs/MODULE_STRUCTURE.md** may contain the same layout and more detail.
