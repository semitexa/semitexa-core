# Changelog

Changes to `semitexa/core` that a consuming application can notice. Sections are
`## <version> — <date>` (newest first); `## Unreleased` collects changes until the
next release tag. This file is machine-read by `update:changelog` and the OS
"What's new" surface — keep entries short and operator-facing.

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
