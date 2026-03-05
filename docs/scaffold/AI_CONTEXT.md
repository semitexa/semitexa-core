# AI Context & Rules (scaffold template)

This is a **reference copy** of the template used to generate **docs/AI_CONTEXT.md** in the project root when you run `bin/semitexa init` or `bin/semitexa init --only-docs --force`. The canonical template lives in [resources/init/docs/AI_CONTEXT.md](../../resources/init/docs/AI_CONTEXT.md).

---

# AI Context & Rules

> **SYSTEM PROMPT ADDENDUM**: Read this file to understand how to work with this specific codebase.

## 📖 Entry point (philosophy & ideology)

Before diving into package-specific docs, read Semitexa's **why** and **goals** so your changes align with the project's intent:

- **Vision and motivation (human):** **vendor/semitexa/docs/README.md** — pain points, economics, Swoole, elegance paradox, AI-oriented design.
- **Philosophy for agents:** **vendor/semitexa/docs/AI_REFERENCE.md** — same ideas in a pragmatic form (Pain → Goal → For agents). Use it to align suggestions and generated code with project goals.

Detailed, technical documentation stays in each package (e.g. vendor/semitexa/core/docs, module READMEs) and describes how that package works.

## ⚡ Core Philosophy
**"Make it work, make it right, make it fast."**
- **Stack**: PHP 8.4+, Swoole, Semitexa Framework.
- **Architecture**: Modular, Stateful (Swoole), Attribute-driven.

## 🚫 Critical Rules (DO NOT BREAK)
1.  **No Monoliths**: Do NOT put code in `src/` root (e.g., `src/Controller`). ALWAYS create a **Module** in `src/modules/`.
2.  **No Global State**: Remember, the app runs in a loop. Static properties persist across requests. Avoid them or reset them explicitly.
3.  **Attributes Over Config**: Routes, Events, and Services are defined via PHP Attributes (`#[AsRequest]`, etc.), not YAML/XML config files.
4.  **Strict Typing**: Use DTOs for Requests and Responses. Do not pass `array $data` generally.

## 🛠 Common Tasks

### Adding a New Page/Endpoint
1.  **Create Module**: `src/modules/MyFeature/` + `composer.json` (`type: semitexa-module`).
2.  **Request DTO**: Create `Application/Payload/Request/MyPagePayload.php` with `#[AsPayload(path, methods, responseWith)]`. See **docs/MODULE_STRUCTURE.md** (Payload/Request, Session, Event; Handlers by type).
3.  **Handler**: Create `Application/Handler/PayloadHandler/MyPageHandler.php` with `#[AsPayloadHandler(payload: ..., resource: ...)]`.
4.  **Response**: Return `Response::json(...)` or a Twig-based Response DTO. After adding/changing payloads run **`bin/semitexa registry:sync:payloads`**.

### Adding a Service
1.  Define Interface: `Domain/Contract/MyServiceInterface.php` (no attribute on the interface).
2.  Implement: `Infrastructure/Service/MyService.php` with `#[AsServiceContract(of: MyServiceInterface::class)]` implementing the interface.
3.  Inject: In consumers use **property injection** — `#[InjectAsReadonly]`, `#[InjectAsMutable]`, or `#[InjectAsFactory]` on protected properties. No constructor injection. See **vendor/semitexa/core/docs/SERVICE_CONTRACTS.md** and **vendor/semitexa/core/src/Container/README.md**.

## 🔍 Discovery
- **Routes**: Built from `src/registry/Payloads/` (generated); module request DTOs live in `Application/Payload/Request/`. Session/Event DTOs in `Payload/Session/`, `Payload/Event/`. See **docs/MODULE_STRUCTURE.md**.
- **Modules**: Discovered via `composer.json` in `src/modules/*` and `pakages/*` (or vendor).

## 🧪 Testing
- **Unit**: `vendor/bin/phpunit`
- **Location**: `tests/` or `src/modules/*/Tests/`.
