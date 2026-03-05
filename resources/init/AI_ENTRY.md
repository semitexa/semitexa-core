# Semitexa Framework - AI Assistant Entry Point

> **Guiding principle:** Make it work → Make it right → Make it fast.

## Foundational context (stack versions)

Use these versions so you don't assume outdated syntax or APIs:

- **PHP:** ^8.4 (see `composer.json` / `composer.lock`)
- **semitexa/core:** dev-main or v1.x (path packages: `pakages/semitexa-core` or `vendor/semitexa/core`)
- **semitexa/docs:** ^1.0 (AI_REFERENCE, guides)
- **Key dependencies:** Symfony 7.x (console, process, etc.), Twig ^3.10, PSR Container (Semitexa custom DI: AsServiceContract, InjectAsReadonly/Mutable/Factory — see vendor/semitexa/core/src/Container/README.md)

Exact versions are in `composer.lock`. Do not assume Laravel, Illuminate, or Kernel-style middleware — Semitexa has its own module and route discovery.

## Rules and guards

- **Do not** add root-level directories or change module discovery without explicit user approval.
- **Do not** add Composer dependencies without explicit user approval.
- **Do not** create documentation files (README, guides, extra `.md` in the project) unless the user explicitly asks for them.
- **Do not** create or use a `docs/` folder in the project root; use `var/docs/` for AI working files only.

## Read before you change (mandatory)

| Before you… | Read first |
|-------------|------------|
| Understand **why** Semitexa (philosophy, goals, pain) | **vendor/semitexa/docs/README.md** (vision) and **AI_REFERENCE.md** (for agents). Monorepo: **pakages/semitexa-docs/** |
| Create or change **module structure** (folders, Application/…) | **docs/MODULE_STRUCTURE.md** and **vendor/semitexa/core/docs/ADDING_ROUTES.md** |
| Change **service contracts** or DI bindings | **vendor/semitexa/core/docs/SERVICE_CONTRACTS.md**; run `bin/semitexa contracts:list --json` to see current bindings |
| Add **new pages or routes** | **vendor/semitexa/core/docs/ADDING_ROUTES.md** |

## Before you generate code (checklist)

- **Modules:** only in `src/modules/`; standard layout: `Application/Payload/`, `Application/Resource/`, `Application/Handler/PayloadHandler/`, `Application/View/templates/`.
- **Routes:** only via modules (Request + Handler with attributes). Do not add routes in project `src/` (App\ is not discovered).
- **Payloads:** after adding or changing Payload classes (or `#[AsPayloadPart]` traits), run **`bin/semitexa registry:sync:payloads`** so routes are generated in `src/registry/Payloads/`. Without this, new payloads have no route; the app will throw a clear error at startup if you forget.
- **Module autoload:** do not add per-module PSR-4 entries to project root `composer.json`; the framework autoloads from `src/modules/` at runtime.
- **Contracts/DI:** before changing a contract or adding an override, run `bin/semitexa contracts:list` or `contracts:list --json` to see current implementations and active binding.

## Project structure (standalone app)

- **bin/semitexa** – CLI
- **public/** – web root
- **src/** – application code; **new routes** go in **modules** (src/modules/), not in src/Request or src/Handler (App\ is not discovered for routes).
- **src/modules/** – application modules (where to add new pages and endpoints). **Do not add per-module PSR-4 entries to composer.json** – the framework autoloads all modules from src/modules/ via IntelligentAutoloader at runtime.
- **var/log**, **var/cache** – runtime
- **var/docs/** – working directory for AI only: temporary notes, plans, drafts. Content not committed (`.gitignore`). **Do not create or use a docs/ folder in the project root.**
- **AI_NOTES.md** – your own notes for AI (created once, never overwritten by the framework).
- **vendor/semitexa/** – framework packages

## Framework docs (in vendor — read these, do not copy into project)

- **vendor/semitexa/docs/README.md** and **AI_REFERENCE.md** – philosophy and goals; read first so changes align with project intent.
- **vendor/semitexa/core/docs/ADDING_ROUTES.md** – how to add new pages/routes (modules only)
- **vendor/semitexa/core/docs/RUNNING.md** – how to run the app (Docker)
- **vendor/semitexa/core/docs/attributes/** – Request, Handler, Response attributes
- **vendor/semitexa/core/docs/SERVICE_CONTRACTS.md** – service contracts, active implementation, and **contracts:list** command
- **vendor/semitexa/docs/README.md** – package map; **vendor/semitexa/docs/guides/CONVENTIONS.md** – conventions (when semitexa/docs is installed)

## Machine-readable commands (for AI agents and scripts)

These commands produce **stable, parseable output** — use them instead of scraping human-oriented tables:

| Command | Output | Use when |
|---------|--------|----------|
| `bin/semitexa contracts:list --json` | JSON: `contracts[]` with `contract`, `active`, `implementations` | Debugging DI, checking bindings before/after changing contracts or modules. See vendor/semitexa/core/docs/SERVICE_CONTRACTS.md. |
| `bin/semitexa registry:sync` | Syncs payloads + contracts into `src/registry/` | After adding/changing Payloads or contract implementations. Run after `composer install`/`update` (plugin runs it automatically). |

(More commands may be added here with `--json` or similar; check `bin/semitexa list` and framework docs.)

## Debugging: service contracts (for AI agents and developers)

To see **which interface is bound to which implementation** (and which implementation is active when several modules provide one):

```bash
bin/semitexa contracts:list
```

Table: Contract (interface) | Implementations (module → class) | Active. Use when debugging DI or after adding/removing modules.

**For AI agents:** use `bin/semitexa contracts:list --json` for stable, parseable output. See vendor/semitexa/core/docs/SERVICE_CONTRACTS.md for details.

## Quick start

1. Read this file; follow **Read before you change** above when modifying modules, contracts, or routes.
2. For new routes: read vendor/semitexa/core/docs/ADDING_ROUTES.md
3. Run (Docker):

```bash
cp .env.example .env
```

```bash
bin/semitexa server:start
```

4. Default URL: http://0.0.0.0:{{ default_swoole_port }} (see .env SWOOLE_PORT). See vendor/semitexa/core/docs/RUNNING.md for details.
