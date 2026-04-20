# AsPayload

## Description

`#[AsPayload]` marks a class as an HTTP request DTO and declares its route metadata.

This is the canonical Semitexa request attribute. It defines where the route lives, which HTTP methods it accepts, which Resource DTO should be instantiated, and which response formats the endpoint can negotiate.

Request/Payload classes must live in modules under `src/modules/`, `packages/`, or `vendor/`.

## Usage

```php
use Semitexa\Core\Attribute\AsPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

#[AsPayload(
    doc: 'docs/attributes/AsPayload.md',
    path: '/api/users',
    methods: ['GET'],
    name: 'api.users.list',
    responseWith: ResourceResponse::class,
)]
final class UserListPayload
{
}
```

## Parameters

### Common

- `path` - Route path. Required unless inherited from `base`.
- `methods` - Allowed HTTP methods. Defaults to `['GET']`.
- `responseWith` - Resource DTO class the framework will instantiate for this route.
- `name` - Optional route name. Defaults to the short class name.

### Routing

- `requirements` - Regex constraints for path parameters.
- `defaults` - Default route parameter values.
- `options` - Additional route options.
- `tags` - Extra route metadata.
- `public` - Whether the route is public.
- `base` - Base payload to inherit route metadata from.
- `overrides` - Strict override target for route replacement chains.

### Content negotiation

- `consumes` - Request content types accepted by the route.
- `produces` - Response content types the route can return.

When `produces` is declared on an SSR page route, Semitexa uses it in two places:

1. Response negotiation against the incoming `Accept` header or `?_format=...`.
2. `<link rel="alternate" type="...">` tags rendered into the page `<head>` for the non-HTML variants declared by the current payload DTO.

Example:

```php
#[AsPayload(
    path: '/products',
    methods: ['GET'],
    responseWith: ProductPageResource::class,
    produces: ['text/html', 'application/json'],
)]
final class ProductPagePayload
{
}
```

This allows the same route to serve HTML normally and advertise `/products?_format=json` as an alternate machine-readable representation.

## Environment values

Any string attribute value can use env resolution:

- `env::VAR_NAME`
- `env::VAR_NAME::default_value`
- `env::VAR_NAME:default_value` (legacy)

Example:

```php
#[AsPayload(
    path: 'env::API_LOGIN_PATH::/api/login',
    methods: ['POST'],
    name: 'env::API_LOGIN_ROUTE_NAME::api.login',
    responseWith: ResourceResponse::class,
)]
final class LoginPayload
{
}
```

For a fuller explanation of environment-controlled route changes, see [Payload Route Overrides Via `.env`](../PAYLOAD_ENV_ROUTE_OVERRIDES.md).

## Guidance

- Always set `responseWith`.
- Use `requirements` for path params instead of validating route shape later in the handler.
- Use `produces` only for formats the route genuinely supports.
- Do not add `application/json` to SSR page payloads unless the route is meant to expose a real JSON alternate.

## Related

- [AsPayloadHandler](AsPayloadHandler.md)
- [AsPayloadPart](AsPayloadPart.md)
