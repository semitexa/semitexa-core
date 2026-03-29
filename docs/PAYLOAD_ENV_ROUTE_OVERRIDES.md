# Payload Route Overrides Via `.env`

Semitexa lets a payload keep its route contract in PHP while still allowing operations to change the URL shape through environment variables.

This is useful when:

- a deployment needs a different public path without patching PHP code
- a legacy URL must stay alive in one environment but not another
- a demo or docs route needs to move between local, staging, and production setups

## The mechanism

`#[AsPayload]` accepts env-resolved string values.

The most common case is `path`:

```php
#[AsPayload(
    path: 'env::DOCS_PRODUCT_ROUTE_PATH::/docs/products',
    methods: ['GET'],
    responseWith: ProductDocsResource::class,
)]
final class ProductDocsPayload
{
}
```

Resolution rules:

- `env::VAR_NAME` -> use the env value, or empty string if missing
- `env::VAR_NAME::default_value` -> use env value, otherwise the provided default
- `env::VAR_NAME:default_value` -> legacy fallback syntax

The recommended form is the double-colon variant:

```php
path: 'env::DOCS_PRODUCT_ROUTE_PATH::/docs/products'
```

## What can be overridden

Any string field on `#[AsPayload]` can use env resolution, but the most valuable ones for routing are:

- `path`
- `name`
- `responseWith`

In practice, `path` is the main one you should expose for environment-level URL control.

## Example: keep code stable, move the URL per environment

```php
#[AsPayload(
    path: 'env::DEMO_BASIC_ROUTE_PATH::/demo/routing/basic',
    methods: ['GET'],
    responseWith: DemoFeatureResource::class,
    produces: ['application/json', 'text/html'],
)]
final class BasicRoutePayload
{
}
```

`.env`:

```dotenv
DEMO_BASIC_ROUTE_PATH=/demo/http/basic-route
```

Without changing PHP code, the payload now resolves to:

```text
/demo/http/basic-route
```

If the env key is absent, Semitexa falls back to:

```text
/demo/routing/basic
```

## Guidance

- Prefer `env::VAR::default` over `env::VAR` so the route always has a safe fallback.
- Use this for operational flexibility, not as a substitute for route design.
- Keep the payload class name and handler stable even if the public URL changes.
- When the route is an SSR page, its alternate links and route discovery continue to follow the resolved payload path.

## Related

- [AsPayload attribute](attributes/AsPayload.md)
- [Adding routes](ADDING_ROUTES.md)
