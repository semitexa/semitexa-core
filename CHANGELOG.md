# Changelog

Changes to `semitexa/core` that a consuming application can notice. Sections are
`## <version> — <date>` (newest first); `## Unreleased` collects changes until the
next release tag. This file is machine-read by `update:changelog` and the OS
"What's new" surface — keep entries short and operator-facing.

## Unreleased

### Added
- **405 Method Not Allowed.** A path that exists for other methods now answers
  405 with an `Allow` header instead of 404 (`POST /` on a GET-only page).
- **HEAD on every GET route**, without a body — including `/health`, `/metrics`,
  the boot-gate 503 and the fallback 500. SSE and stream routes are excluded.
- `Request::isTrustedForwardedRequest()` is public, so packages can gate a
  proxy-set header on the same loopback / `TRUSTED_PROXIES` rule as
  `X-Forwarded-Proto`.
- `#[InjectAsFactory]` accepts the generated `Factory*` type or `of: Contract::class`.
- `#[WorkerState]` and an opt-in PHPStan rule (`config/phpstan-state-lifetime.neon`)
  requiring every static to declare that it outlives a request.

### Changed
- **Numeric payload fields reject values the cast would change.** `"abc"`,
  `"1.5"` or a 20-digit id for an `int` field is now a 422 field error, instead
  of reaching the handler as `0`, `1` or `PHP_INT_MAX`.
- **`.env` follows dotenv rules.** An inline comment after an unquoted value is
  no longer part of it (`CORS_ALLOW_ORIGIN=https://x # note`), `export KEY=v`
  works, indented `#` lines are comments, and single quotes are literal. Check
  values that relied on a trailing ` # …` being kept.
- Requests that reach a worker still booting wait up to 30 s, then get 503.

### Fixed
- Async event listeners are deferred only inside a request coroutine, and
  post-dispatch hooks run even when a listener throws.
- `server:stop` signals every process on the port.
- Less reflection per request (payload/session attributes, container clones).

## 2026.09.23.1717 — 2026-09-23

### Changed
- **A JSON body is no longer labelled `text/html`.** A route that declares a data
  profile (`json`, `json-ld`, `graphql`) now sends that profile's type when the
  response set none, and keeps the type its response class set instead of
  replacing it. One exception: when content negotiation explicitly picks the
  HTML layout, a body with no `Content-Type` of its own stays on the layout path
  — it may well be HTML. Before, a route with no render handle (`/platform/calendar/events`)
  went out as `text/html`, and any `Accept` other than `application/json` on a
  JSON route with a handle — `text/html`, `*/*`, `application/ld+json` — sent
  its JSON as `text/html; charset=utf-8`. Bodies are unchanged. A client that
  sniffed the body because the header was wrong keeps working; a client that
  trusted the header now reads JSON as JSON.

## 2026.09.22.1020 — 2026-09-22

### Added
- `Semitexa\Core\Support\FrameworkVersion::current()` — the release of Semitexa
  that is running: `SEMITEXA_RELEASE_VERSION` when it holds a release version,
  otherwise the installed version of `semitexa/core`. Returns `null` for a
  working tree or a dev branch, so a footer never advertises a version nobody
  can install.

## 2026.09.19.1020 — 2026-09-19

### Added
- **HSTS, opt-in.** `HSTS_MAX_AGE` (seconds) turns on `Strict-Transport-Security`
  on every response, including health, metrics, static assets and CORS preflight.
  `HSTS_INCLUDE_SUBDOMAINS` and `HSTS_PRELOAD` add the flags; `preload` does not
  imply `includeSubDomains`. Off by default: a browser that has cached the policy
  refuses plain HTTP to the host until it expires, so a lapsed certificate locks
  people out. `HSTS_MAX_AGE=0` withdraws a policy already sent — leave it in
  place until the longest `max-age` you sent has expired; removing the variable
  withdraws nothing. A value that is not a non-negative integer turns the header
  off, and `system:doctor` fails on it (`http.strict-transport-security`).
- `SESSION_COOKIE_SECURE` (`auto` | `always` | `never`, default `auto`) — force
  the `Secure` flag on the session cookie for a deployment behind proxies it
  cannot list.
- `Request::getServedPath()` — the address the visitor is actually at. Differs
  from `getPath()`, which is the path the router matched, once a locale prefix
  has been stripped.
- `system:doctor` check `http.forwarded-proxy-trust`: fails when `APP_URL` is
  `https` and `TRUSTED_PROXIES` is empty or holds no usable entry.

### Changed
- **A route that declares `RenderProfile::Json` is no longer turned into a page
  document.** Before, `Accept: application/json` on such a route answered
  `{"page":…,"content":{"data":[]}}` instead of the handler's own body. Pages,
  which declare no profile, are unchanged.
- When `X-Forwarded-Proto: https` arrives from a peer that is not trusted, the
  session cookie still loses `Secure` (that is correct) but the worker now logs
  one warning naming the peer and the two settings that fix it. Before, it was
  silent.

## 2026.09.17.1352 — 2026-09-17

### Changed
- **The page-JSON decision honours quality values.** Whether a request gets the
  page as a JSON document is now negotiated, not a substring match on `Accept`:
  - `Accept: text/html,application/json;q=0.9` → HTML (was JSON)
  - `Accept: application/json;q=0` → **406** (was JSON)
  - `application/json`, `application/ld+json`, `*/*`, an empty or missing
    `Accept`, and `?_format=json` behave as before.

  A client that sends a mixed `Accept` and expected JSON must now prefer JSON
  (drop HTML, or give it a lower `q`), or use `?_format=json`.
