# semitexa/core

Framework runtime: request/response lifecycle, attribute-driven discovery, DI container, CLI tooling, and Swoole integration.

## Purpose

The foundation of every Semitexa application. Manages the full request lifecycle — from route discovery via PHP 8.4 attributes through handler execution to response rendering. Provides the DI container with two-tier scoping (worker-readonly + request-mutable), the CLI via `bin/semitexa`, and the Composer plugin for classmap-based discovery.

## Role in Semitexa

Root dependency for all Semitexa packages. Every module, platform component, and library builds on Core's attribute discovery, container, and pipeline.

## Key Features

- `#[AsPayload]` / `#[AsPayloadHandler]` attribute-driven routing
- `AttributeDiscovery` and `ClassDiscovery` via Composer classmap
- Two-tier DI: `SemitexaContainer` (worker-scoped readonly) + `RequestScopedContainer` (per-request mutable)
- `RouteExecutor` pipeline with exception mapping and response decoration
- `ExceptionResponseMapperInterface` / `RouteMetadataResolverInterface` / `RouteInspectionRegistryInterface` seams
- `HttpStatus` enum replacing magic integers
- `EventDispatcher` with sync/defer/queued modes
- Redis and SwooleTable session handlers
- `bin/semitexa` CLI (server:start, db:migrate, code generation)
- Composer plugin for framework integration

## Notes

Core is a Composer plugin (`type: composer-plugin`). It installs framework scaffolding and provides classmap-based discovery. The two-tier container design is essential for Swoole: readonly bindings survive across requests, mutable bindings are cloned per request.
