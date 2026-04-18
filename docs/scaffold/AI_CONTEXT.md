# AI Context & Rules (scaffold template)

This is a **reference copy** of the template used to generate **docs/AI_CONTEXT.md** in the project root when you run `bin/semitexa init` or `bin/semitexa init --only-docs --force`. The canonical scaffold asset now lives in **semitexa/ultimate** at [semitexa-ultimate/docs/AI_CONTEXT.md](https://github.com/semitexa/semitexa-ultimate/blob/main/docs/AI_CONTEXT.md).

---

# AI Context & Rules

> **SYSTEM PROMPT ADDENDUM**: Read this file to understand how to work with this specific codebase.

## 📖 Entry point (philosophy & ideology)

Before diving into package-specific docs, read Semitexa's **why** and **goals** so your changes align with the project's intent:

- **Project docs hub:** **docs/README.md** — start here for project-level navigation and documentation ownership.
- **Vision and motivation (human):** **vendor/semitexa/docs/README.md** — pain points, economics, Swoole, elegance paradox, AI-oriented design.
- **Philosophy for agents:** **vendor/semitexa/docs/AI_REFERENCE.md** — same ideas in a pragmatic form (Pain → Goal → For agents). Use it to align suggestions and generated code with project goals.

Project-specific guidance lives in `docs/`. Detailed framework reference stays in package docs (e.g. `vendor/semitexa/core/docs`, module READMEs).

## ⚡ Core Philosophy
**"Make it work, make it right, make it fast."**
- **Stack**: PHP 8.4+, Swoole, Semitexa Framework.
- **Architecture**: Modular, Stateful (Swoole), Attribute-driven.

## 🚫 Critical Rules (DO NOT BREAK)
1.  **No Monoliths**: Do NOT put code in `src/` root (e.g., `src/Controller`). ALWAYS create a **Module** in `src/modules/`.
2.  **No Global State**: Remember, the app runs in a loop. Static properties persist across requests. Avoid them or reset them explicitly.
3.  **Attributes Over Config**: Routes, Events, and Services are defined via PHP Attributes (`#[AsPayload]`, `#[AsPayloadHandler]`, etc.), not YAML/XML config files.
4.  **Strict Typing**: Use DTOs for Requests and Responses. Do not pass `array $data` generally.

## 🛠 Common Tasks

### Adding a New Page/Endpoint
1.  **Create Module**: `src/modules/MyFeature/` + `composer.json` (`type: semitexa-module`).
2.  **Request DTO**: Create `Application/Payload/Request/MyPagePayload.php` with `#[AsPayload(path, methods, responseWith)]`. See **vendor/semitexa/core/docs/MODULE_STRUCTURE.md** (Payload/Request, Session, Event; Handlers by type).
3.  **Handler**: Create `Application/Handler/PayloadHandler/MyPageHandler.php` with `#[AsPayloadHandler(payload: ..., resource: ...)]`.
4.  **Response**: Return `Response::json(...)` or a Twig-based Response DTO. Do not treat `registry:sync` as a required manual step for ordinary payload changes unless a specific package doc requires it.

### Adding a Service
1.  Define Interface: `Domain/Contract/MyServiceInterface.php` (no attribute on the interface).
2.  Implement: `Infrastructure/Service/MyService.php` with `#[AsServiceContract(of: MyServiceInterface::class)]` implementing the interface.
3.  Inject: In consumers use **property injection** — `#[InjectAsReadonly]`, `#[InjectAsMutable]`, or `#[InjectAsFactory]` on protected properties. No constructor injection. See **vendor/semitexa/core/docs/SERVICE_CONTRACTS.md** and **vendor/semitexa/core/src/Container/README.md**.

## 🔍 Discovery
- **Project Graph (use on demand)**: Start from the task. Use `bin/semitexa ai:task "<task>"` when available, or manually choose the narrowest relevant command. Run `bin/semitexa ai:review-graph:generate --json` and `bin/semitexa ai:review-graph:stats --json` only when you need fresh graph-backed answers. This gives you the structural map of the codebase — modules, classes, dependencies, event flows, execution paths. See **packages/semitexa-project-graph/docs/AI_INTEGRATION.md** for the full guide.
  - `ai:review-graph:context "<task>" --format=json` — get relevant context for any task
  - `ai:review-graph:event-trace <Event> --format=json` — trace full event lifecycle
  - `ai:review-graph:flow-trace <Flow> --format=json` — trace execution flow
  - `ai:review-graph:impact <Component> --format=json` — analyze blast radius of changes
- **Routes**: Built from `src/Registry/Payloads/` (generated); module request DTOs live in `Application/Payload/Request/`. Session/Event DTOs in `Payload/Session/`, `Payload/Event/`. See **vendor/semitexa/core/docs/MODULE_STRUCTURE.md**.
- **Modules**: Discovered via `composer.json` in `src/modules/*` and `packages/*` (or vendor).

## 🧪 Testing
- **Unit**: `vendor/bin/phpunit`
- **Location**: `tests/` or `src/modules/*/Tests/`.
