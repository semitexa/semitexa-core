# Changelog

Changes to `semitexa/core` that a consuming application can notice. Sections are
`## <version> — <date>` (newest first); `## Unreleased` collects changes until the
next release tag. This file is machine-read by `update:changelog` and the OS
"What's new" surface — keep entries short and operator-facing.

## Unreleased

### Security
- **A sign-out is no longer undone by a request that was on its way.** A
  request that had read the session before a sign-out (or a sign-in) saved, and
  finished after it, wrote the old session back under the old id with a full
  lifetime and sent that cookie again: after a sign-out the signed-in session
  was back for anyone holding the old cookie, and after a sign-in the visitor
  was signed out. A session id a browser holds is now written only while the
  store still holds a session under it; otherwise the request stores nothing and
  sends no session cookie. In Redis the check and the write are one script. On
  the Swoole table they are made under a lock all workers share, and so is every
  other change of that session's row (64 locks, created with the table before
  the workers fork; a session write waits for another only on the same lock). A
  custom `SessionHandlerInterface` gets a read followed by a write: a sign-out
  that lands exactly between them is still written over; implementing
  `HeldSessionWriterInterface` closes that. To notice: a session that expired or
  was evicted while its request ran is no longer started again by that request.

### Fixed
- **A request sent just before a sign-in no longer signs the visitor out.**
  `regenerate()` deleted the old session id at once, so a poll or a reconnecting
  stream that left the browser with the old cookie and was answered after the
  sign-in found an id the store did not know. Its response expired that cookie
  (before 2026.11.25.1338: set a guest's), and in the browser that replaced the
  signed-in one. The old id is now kept for 10 seconds
  (`Session::RETIRED_ID_GRACE_SECONDS`) as a marker without data, and a request
  that presents it is served as a guest and sent no session cookie and no
  `XSRF-TOKEN`; what it writes to its session is not stored. The marker does not
  lead to the new session: the id from before a sign-in still opens nothing. To
  notice: for those 10 seconds the store holds one more key per sign-in or
  sign-out (`{"__retired__":1}`), and a sign-in whose handler runs on a retired
  id, or beside another sign-in on the same session, answers 503 with
  `Retry-After`. On a full Swoole table the sign-in no longer evicts its own
  marker.

## 2026.11.25.1338 — 2026-11-25

### Security
- **A guest request that does not use the session no longer creates one.** Every
  response used to write a session row and send `semitexa_session` and
  `XSRF-TOKEN`: an anonymous 404, a 401 of a webhook route, each probe of a
  scanner. A flood of them filled the store, and on the Swoole table (4096 rows
  by default) evicted the sessions of signed-in visitors. A session is now stored,
  and its cookies sent, when the visitor presented one the store holds, when the
  request wrote to it, or when it read its id or its CSRF token (`csrf_field()`,
  `csrf_token()`). A stored session is still written on every request, so its
  lifetime slides as before. To notice: a guest page with no form sets no
  `XSRF-TOKEN` cookie, and a script's first POST from it carries no session
  cookie, so it is not CSRF-checked, as any request without one. A POST refused
  by the CSRF check stores no session either. A session cookie the store no
  longer knows (expired, evicted, lost with a restart of the store) is expired
  in the browser, with its `XSRF-TOKEN`, unless the request starts a new session. Composer installs this version only
  with a `semitexa/ssr` newer than 2026.11.25.0522 (a `conflict`): on an older
  one, a guest's page with deferred or live components would lose its stream's
  session.

### Changed
- **`lint:templates` refuses a POST form without a CSRF token.** Such a form works
  for a new guest, who has no session yet and is not checked, and answers 403 for
  every visitor who has one. With guest sessions created only on use, a page no
  longer shows this by itself, so the lint does, as an error. A form passes with
  `{{ csrf_field() }}` directly inside it, or an `<input>` named `_csrf`; a field
  printed by an included template, a macro or a `{% set %}` variable is not seen. Not checked: a GET
  form, a form without a method, a form that posts to an absolute URL written in
  the template (`http://`, `https://`, `//`), markup in a comment or
  `{% verbatim %}`. A literal absolute URL is taken for another site: a form that
  posts to this app uses a path, or a Twig expression, which is checked. A method
  written as a Twig expression counts as POST, and so does a button with
  `formmethod="post"`; a submit button with its own `formaction` posts there
  with the form's method.
  After the update, run `bin/semitexa
  lint:templates` and add the field where it reports one.

## 2026.11.25.0522 — 2026-11-25

### Fixed
- **A project event listener in its module's `Application/Handler/DomainListener/`
  is registered.** The module-structure guide puts it there, but only
  `Event/DomainListener/` was read, so such a listener never ran and boot said
  nothing. Both directories are read now, and an `#[AsEventListener]` class under
  `App\` outside them is reported at boot instead of skipped. After the update
  such listeners start running: check what they do before deploying. In strict
  boot mode a misplaced listener now stops boot.

## 2026.11.23.1729 — 2026-11-23

### Added
- **`contracts:list` says what decided each contract's active implementation,
  and `lint:di` warns about a tie between two project modules.** For a contract
  with two or more implementations, `decided_by` names what separated the active
  one from its closest rival:
  - `extends`: a declared relation, `extra.semitexa-module.extends`;
  - `project-module`: a project module over a package;
  - `core-default`: any module over Core;
  - `discovery-order`: nothing relates them, so the module found first won.

  `tied_with` lists the implementations it beat only by that order, and
  `ServiceContractRegistry::getDecisions()` and `getProjectModuleTies()` carry
  the same. Until now such a tie resolved silently. `lint:di` prints a warning,
  without failing, when both tied modules are the project's own, naming both and
  the `extends` that would decide it.

### Changed
- **The service contract registry alone decides a contract's active
  implementation.** The container also asked a generated
  `src/registry/Contracts/*Resolver.php` for it, in `get()` and for a factory's
  default, while property injection followed the registry, so an edited
  `getContract()` split the container and `contracts:list` showed neither side.
  No edit survived anyway: `registry:sync:contracts` rewrote every resolver after
  each composer install or update. Resolvers are no longer read, and
  `registry:sync:contracts` no longer writes them: it removes the generated ones
  it finds (a file without the generated header stays) and still generates the
  factory classes. To choose between two implementations, declare the relation
  (`extends`) in the module's `composer.json`.

### Fixed
- **A queue worker runs only the jobs this boot queues.** A queue message names
  the classes the worker builds and runs, and whoever can write to the broker
  chooses them. The worker built the request, response or event a message named
  first, through `set*()` methods of any visibility, and then ran any container
  service with a `handle()`: every synchronous payload handler, or a service that
  is no listener at all. A handler message now runs only a handler discovery
  registered as `Queued`, for the request and response classes it is registered
  for, and an event message only a listener registered as `Queued` for exactly
  that event class. Anything else goes to the dead letter before anything of it
  is built. `PayloadSerializer::hydrate()` calls only public, non-static setters,
  as `PayloadHydrator` does: a DTO that relied on a private or static `set*()`
  to come back from a queue loses that field.

## 2026.11.23.1215 — 2026-11-23

### Changed
- **A session cookie is used only when the server issued its id and still holds
  the session.** Any 32-byte cookie value used to be taken as the session id,
  stored under and sent back, so a client could choose the id a session lives
  under (session fixation; sign-in and sign-out already replaced the id). The
  id must now be 32 lowercase hex characters and present in the session store;
  anything else gets a new id. Noticeable in one case: a browser whose session
  the store no longer has (expired, or the in-memory table lost at a restart)
  gets a new cookie value instead of an empty session under the old one.

### Fixed
- **`CrossSiteRead` compares an `Origin` as scheme, host and port.** With no
  `Sec-Fetch-Site`, a page on another port or scheme of the same host was taken
  for the app's own when the `Host` header named no port: `http://localhost:3000`
  could read a developer surface (the Observatory, the UI Workbench) served at
  `Host: localhost`, and so could `https://` beside `http://`. A port left out
  is now the scheme's default on both sides, so `Host: example.test:80` with
  `Origin: http://example.test` is the same origin; it was refused before.
  Browsers send no `Sec-Fetch-*` to a plain-http host that is not localhost,
  `127.0.0.1` or a `*.localhost` name (a LAN address, `myapp.test`), so there the
  `Origin` is the only check. Behind a TLS-terminating proxy the request is
  `https` only when a trusted `X-Forwarded-Proto` says so (`TRUSTED_PROXIES`;
  loopback by default): from a proxy that is not listed, the requests of an
  `https` page that carry an `Origin` (a fetch, a form POST) are refused, the
  safe side. A `Host` that is more than a host and a port
  (`localhost@evil.example`) is refused too.

## 2026.11.23.0659 — 2026-11-23

### Added
- **`worker:run`, a new command: the worker's roles as forks of one process.**
  It runs every line of `worker:processes`, each started again after its
  pause. Nothing uses it yet: the scaffold's worker container, which starts a
  `php` for each line, switches to it in the next semitexa/installer release.
  Measured on a new project: five roles each loaded the framework for
  themselves, about 74 MB of private memory a role (370 MB at rest); forked
  from the one process that loaded it they add 3 to 6 MB each (about 95 MB in
  all). Each role is still its own process, named `semitexa <command>`:
  `kill <pid>` restarts one, and one that dies is reported and started again.
  A role started again runs the code loaded when `worker:run` started, not
  what is on disk: `kill -HUP` on `worker:run` makes every role finish the work
  in hand and loads the code again without the process exiting, and a role
  started again while a loaded file has changed names the files in the log.

### Changed
- **A worker that finds a valid classmap cache no longer reads every source
  file to learn what it declares.** The classmap discovery builds by walking
  the sources is kept in `var/cache/discovery` and checked against them on
  every boot by each file's mtime, size and ctime: an edited, new or removed
  file is still seen with nothing to clear, and only changed files are read.
  On an empty cache (a first boot, or the one after `cache:clear`) every file
  is read as before, then the result is stored. Attribute lookups are not
  cached: every process still loads the classes of the map to read them.
  `cache:clear` removes the cache; `cache:clear --twig` does not.

## 2026.11.21.1434 — 2026-11-21

### Changed
- **`APP_SECRET` is required outside dev and test, and has been since
  2026.10.29.1052.** That release did not say so here. With `APP_ENV` set to
  anything but `dev` or `test` and no `APP_SECRET`, signed components, encrypted
  values and signed links each fail with a 500 at their first use. Set it in
  `.env` to a long random value, for example `openssl rand -hex 32`.
  `bin/semitexa system:doctor` reports a missing one (check `app.secret`).

### Added
- **The server refuses to start when it cannot write where it logs.** A
  command run as root (`bin/semitexa update`, for one) can leave `var/` owned by
  root; the server, running as another user, then could not create its daily
  `swoole.log.<date>` and hung at every start with no log line. Now the start
  stops with an `[Semitexa] THE SERVER WILL NOT START` block on stderr naming
  the path, its owner and mode, the process uid and the fix, and exits with
  code 1. The fix is `chown -R <uid>:<gid> var` (or `bin/semitexa server:start`,
  which reclaims ownership) for another owner, and `chmod u+w <path>` when the
  server's user owns the path and the mode forbids the write. Checked: the
  directory of `SWOOLE_LOG_FILE`, `var/log` and `var/run`. With Swoole's
  log rotation off (`single`) an existing log file is checked itself, so a
  writable file in a read-only directory still starts.
- **`system:doctor` check `var.writable`.** It fails when one of those paths
  cannot be written by, or is not owned by, the user running the check. It
  creates nothing: a missing directory is reported, and made by the server at
  its start. Run it as the server's user; run as root it only warns.

## 2026.11.03.2020 — 2026-11-03

### Added
- **`Http\ThisMachine::sent()`** — whether a request came from the machine the
  app runs on (loopback or the container's gateway, forwarded hops on a Docker
  bridge gateway, a local Host name). The bar for developer surfaces that
  answer without a sign-in. Core alone gates nothing: the Observatory,
  Explorer and Workbench switch to it in their own packages' next release
  (semitexa/dev, semitexa/platform-ui), which require this core.

## 2026.11.03.0259 — 2026-11-03

### Changed
- **A guest POST is CSRF-checked too.** Every unsafe request (POST, PUT, PATCH,
  DELETE) that carries the session cookie must present the session's token,
  signed in or not. A guest form without `{{ csrf_field() }}` (or a script
  without the `X-CSRF-Token` header) now gets **403**. Add the field, or mark
  a machine route `#[CsrfExempt]`. Before, such a form worked for guests and
  was refused once its user signed in.

## 2026.10.09.0942 — 2026-10-09

### Changed
- **`FrameworkVersion::current()` names the release, not core's tag.** It now
  reads the `semitexa/ultimate` release the updater recorded in
  `var/run/semitexa-release.json`, between `SEMITEXA_RELEASE_VERSION` and the
  core tag. A cut that did not re-tag core (2026.10.09.0714) left every footer
  on the release before. A changed record is picked up without a restart.

### Added
- `FrameworkVersion::record()` / `forget()` and `FrameworkVersion::RECORD_PATH`.

## 2026.10.08.0620 — 2026-10-08

### Changed
- **Request size limit is 32 MiB** (`SWOOLE_PACKAGE_MAX_LENGTH`, checked at boot,
  minimum 64 KiB). Swoole's 2 MiB default silently dropped phone-photo uploads.
  A request is buffered up to this size in worker memory.
- **`Session::regenerate()` changes the id at once**; `save()` destroys the old
  one. Contexts and state bound later in the same request follow the new id.
- Live re-runs and KISS work run as the page's visitor (session and auth).

### Added
- `WatchScopesOf`, `DeclaresWatchScopesInterface`, `DeclaresRequiredPermissionsInterface`.
- `gte` / `lte` collection filters; `csrf_field()`; `PageTimeline` (dev).

## 2026.09.27.0404 — 2026-09-27

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
