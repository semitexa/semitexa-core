# About Semitexa

"Make it work, make it right, make it fast." — Kent Beck

Semitexa isn't just a framework; it's a philosophy of efficiency.
Engineered for the high-performance Swoole ecosystem and built with an AI-first mindset,
it allows you to stop fighting the infrastructure and start building the future.

Simple by design. Powerful by nature.

## Requirements

- Docker and Docker Compose
- Composer (on host for install)

## Install

From an empty folder (get the framework and install dependencies):

```bash
composer require semitexa/core
```

From a clone or existing project (dependencies already in `composer.json`):

```bash
composer install
```

Then:

```bash
cp .env.example .env
```

## Run (Docker — supported way)

```bash
bin/semitexa server:start
```

To stop:

```bash
bin/semitexa server:stop
```

Default URL: **http://0.0.0.0:{{ default_swoole_port }}** (configurable via `.env` `SWOOLE_PORT`).

## Documentation

Project-level docs live in `docs/`. Package-level deep reference lives in `vendor/` (or `packages/` in the monorepo).

| Topic | File or folder |
|-------|----------------|
| **AI context for this project** | [docs/AI_CONTEXT.md](docs/AI_CONTEXT.md) |
| **Running the app** — Docker, ports, logs | [vendor/semitexa/core/docs/RUNNING.md](vendor/semitexa/core/docs/RUNNING.md) |
| **Adding pages and routes** — modules, Request/Handler | [vendor/semitexa/core/docs/ADDING_ROUTES.md](vendor/semitexa/core/docs/ADDING_ROUTES.md) |
| **Attributes** — AsPayload, AsPayloadHandler, AsResource, etc. | [vendor/semitexa/core/docs/attributes/README.md](vendor/semitexa/core/docs/attributes/README.md) |
| **Service contracts** — contracts:list, active implementation | [vendor/semitexa/core/docs/SERVICE_CONTRACTS.md](vendor/semitexa/core/docs/SERVICE_CONTRACTS.md) |
| **Package map & conventions** (if semitexa/docs is installed) | [vendor/semitexa/docs/README.md](vendor/semitexa/docs/README.md) · [vendor/semitexa/docs/guides/CONVENTIONS.md](vendor/semitexa/docs/guides/CONVENTIONS.md) |

Use `docs/` for app-level guides and decisions. Use package docs in `vendor/semitexa/` for framework internals and reference material.

## Structure

- `src/modules/` – your application modules (add new pages and endpoints here). New routes only in modules.
- `docs/` – project-level canonical documentation for this app.
- `var/docs/` – working directory for notes, drafts, and research; not canonical.
- `AI_ENTRY.md` – entry point for AI assistants; `AI_NOTES.md` – your notes (never overwritten).

## Tests

```bash
composer require --dev phpunit/phpunit
```

```bash
vendor/bin/phpunit
```

Use `phpunit.xml.dist`; add tests in `tests/`.
