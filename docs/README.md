# core docs

The documentation for this package lives in the documentation hub
(`semitexa/docs`), served at `/docs`. This folder keeps only the package's
own release record.

| Subject | Page |
|---|---|
| Creating a module and its first route | `routing/adding-routes` |
| Moving a route's URL through `.env` | `routing/env-route-override` |
| The canonical module folder layout | `get-started/module-structure` |
| Payload validation, rules and hydration | `validation/payload-validation` |
| Request pipeline events and server lifecycle hooks | `events/pipeline` |
| Sync vs async dispatch, the async worker | `events/dispatch-configuration` |
| Sessions, session segments, flash messages, cookies | `runtime/sessions-and-cookies` |
| Per-request tenant, auth and locale | `runtime/request-context` |
| Which implementation a contract resolves to | `di/contract-resolution` |
| The Twig cache under long-running workers | `rendering/template-cache` |

Every attribute this package defines is listed, generated from the source,
in `reference/attributes-core.md`.
