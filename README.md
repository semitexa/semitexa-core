# Semitexa Core

Framework runtime: request/response lifecycle, attribute-driven discovery, DI container, CLI tooling, and Swoole integration.

## Purpose

The foundation of every Semitexa application. Manages the full request lifecycle — from route discovery via PHP 8.4 attributes through handler execution to response rendering. Provides the DI container with two-tier scoping (worker-readonly + request-mutable), the PHP console behind `bin/semitexa`, and a Composer plugin that keeps the generated registry in sync.

## Install

Included in every project created by the installer (https://semitexa.com/install.sh).

Requires PHP 8.4 with Swoole 6.x. Both are provided by the project's Docker image, so the host needs only Docker with Compose v2.

## Role in Semitexa

Root dependency for all Semitexa packages. Every module, platform component, and library builds on Core's attribute discovery, container, and pipeline.

## Key Features

- Attribute-driven routing: `#[AsPublicPayload]` (core), `#[AsProtectedPayload]` / `#[AsServicePayload]` (semitexa/authorization) on the request DTO, `#[AsPayloadHandler]` on the handler
- route-level `produces` / `consumes` metadata for content negotiation
- `AttributeDiscovery` and `ClassDiscovery` via Composer classmap
- Two-tier DI: `SemitexaContainer` (worker-scoped readonly) + `RequestScopedContainer` (per-request mutable)
- `RouteExecutor` pipeline with exception mapping and response decoration
- `ExceptionResponseMapperInterface` / `RouteMetadataResolverInterface` / `RouteInspectionRegistryInterface` seams
- `HttpStatus` enum replacing magic integers
- `EventDispatcher` with sync, async (deferred) and queued listener execution
- Redis and SwooleTable session handlers
- The console behind `bin/semitexa` (`server:start`, `orm:sync` via semitexa/orm, `make:*` generators via semitexa/dev)
- Composer plugin: runs `registry:sync` after `composer install` / `update` and generates test PSR-4 entries on autoload dump

## Notes

Core is a Composer plugin (`type: composer-plugin`). The plugin runs `registry:sync` after install/update and adds test PSR-4 entries for package test fixtures; it does not install any scaffolding (project scaffolding ships in semitexa/ultimate). Discovery itself (`ClassDiscovery`) reads Composer's classmap. The two-tier container design is essential for Swoole: readonly bindings survive across requests, mutable bindings are cloned per request.

Docs: https://semitexa.com/docs
