# Semitexa Core

Core framework functionality for Semitexa: request/response handling, attributes, discovery, CLI, and Swoole integration.

## Installation

Usually included when you create or install a Semitexa application:

```bash
composer require semitexa/core
```

## What's inside

- **Request / Response / Handler** — Attributes `#[AsRequest]`, `#[AsRequestHandler]`, `#[AsResponse]`; discovery and routing
- **CLI** — `bin/semitexa` with `init`, `server:start`, `server:stop`, `server:restart`, and code-generation commands
- **Container** — PSR-style DI; request-scoped container for Swoole
- **Docs** — In this package: [docs/ADDING_ROUTES.md](docs/ADDING_ROUTES.md), [docs/RUNNING.md](docs/RUNNING.md), [docs/attributes/README.md](docs/attributes/README.md)

## Documentation

| Topic | File |
|-------|------|
| Adding pages and routes (modules) | [docs/ADDING_ROUTES.md](docs/ADDING_ROUTES.md) |
| Running the app (Docker) | [docs/RUNNING.md](docs/RUNNING.md) |
| Sessions and cookies | [docs/SESSIONS_AND_COOKIES.md](docs/SESSIONS_AND_COOKIES.md) |
| Attributes (AsRequest, AsRequestHandler, etc.) | [docs/attributes/README.md](docs/attributes/README.md) |

For the full framework guide and package map, see the **semitexa/docs** package (e.g. `vendor/semitexa/docs/README.md` when installed).
