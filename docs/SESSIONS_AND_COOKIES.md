# Sessions and cookies

This document describes how sessions and cookies work in Semitexa: lifecycle, storage, API, and integration with the request/response pipeline (including Swoole).

---

## Overview

- **Session**: server-side session storage (Swoole Table), identified by a 32-character cookie. Data is read at request start and saved at request end.
- **Cookies**: read from the incoming request; new cookies are queued in a **CookieJar** and sent in the response as `Set-Cookie` headers.
- **Request-scoping**: each request gets its own `Session`, `CookieJar`, and `Request`. Handlers receive the **same** `CookieJar` instance that the framework uses to send cookies, so any cookie set in a handler is included in the response.

---

## Session

### Contract and implementation

- **Interface**: `Semitexa\Core\Session\SessionInterface`
- **Implementation**: `Semitexa\Core\Session\Session` (in-memory bag + Swoole Table persistence)

Session methods:

| Method | Description |
|--------|-------------|
| `get($key, $default)` | Read value |
| `set($key, $value)` | Write value |
| `has($key)`, `remove($key)`, `clear()` | Key presence / removal |
| `getId()` | Session id (32 hex chars) |
| `regenerate()` | New id after login (prevents fixation); applied on `save()` |
| `flash($key, $value)` | One-time message for the **next** request |
| `getFlash($key, $default)` | Read and consume flash message |
| `getPayload($payloadClass)` | Typed segment (see below) |
| `setPayload($payload)` | Persist typed segment |
| `save()` | Persist to storage (called by framework at end of request) |

### Lifecycle

1. **Request start** (`Application::handleRequest`):
   - `initSessionAndCookies($request)` runs.
   - Session id is taken from cookie `SESSION_COOKIE_NAME` (default `semitexa_session`) if present and 32 chars; otherwise a new id is generated.
   - `Session` is created with `SwooleTableSessionHandler`; data is read from Swoole Table.
   - `Session`, `CookieJar`, and `Request` are stored in the **request-scoped container**.

2. **During request**:
   - Handlers (and other request-scoped services) receive `SessionInterface` and `CookieJarInterface` via constructor. The container injects the **same** instances from the request-scoped cache (see [Request-scoped Session and CookieJar](#request-scoped-session-and-cookiejar)).

3. **Request end**:
   - `finalizeSessionAndCookies($request, $response)` runs: `session->save()`, then the session cookie is added to the CookieJar, and all `Set-Cookie` lines from the jar are attached to the response.
   - The Swoole server sends each `Set-Cookie` line via `rawcookie()` before other headers.

### Storage: Swoole Table

- **Handler**: `Semitexa\Core\Session\SwooleTableSessionHandler` (read/write/destroy).
- **Table holder**: `Semitexa\Core\Session\SwooleSessionTableHolder` (static `getTable()` / `setTable()`).

The table must be created and set **before** workers start. In `server.php`:

```php
$sessionTable = new Table(10000);
$sessionTable->column('data', Table::TYPE_STRING, 65535);
$sessionTable->column('expires_at', Table::TYPE_INT);
$sessionTable->create();
SwooleSessionTableHolder::setTable($sessionTable);
```

Session data is stored as JSON in the `data` column; `expires_at` is used for TTL. Expired rows are removed on read.

### Typed session segments (getPayload / setPayload)

Use DTOs with `#[SessionSegment('name')]` for type-safe, key-isolated session data:

```php
use Semitexa\Core\Session\Attribute\SessionSegment;

#[SessionSegment('user_prefs')]
final readonly class UserPrefsPayload
{
    public function __construct(
        public string $theme = 'light',
        public string $locale = 'uk',
    ) {}
}
```

In a handler:

```php
$prefs = $this->session->getPayload(UserPrefsPayload::class);
$prefs->theme = 'dark';
$this->session->setPayload($prefs);
```

The segment name (`user_prefs`) is the key under which the payload is stored; other keys in the session are unaffected.

### Flash messages

- `$session->flash('message', 'Saved.')` — will be available only on the **next** request.
- `$session->getFlash('message')` — returns the value and removes it.

Use for one-time notices (e.g. “Record saved”) after redirect.

### Environment

| Variable | Default | Description |
|----------|---------|-------------|
| `SESSION_COOKIE_NAME` | `semitexa_session` | Name of the session cookie |
| `SESSION_LIFETIME` | `3600` | Session lifetime in seconds (used for cookie `Max-Age` and storage TTL) |

---

## Cookies

### Reading cookies

- **Request**: `Request` has a `cookies` array (and `getCookie($key, $default)`). It is filled by `RequestFactory::fromSwoole()`:
  - Cookies are taken from `$swooleRequest->cookie` and from the `Cookie` header (parsed if present). Parsed header entries are merged with Swoole’s cookies (Swoole values take precedence).
- **CookieJar**: `CookieJarInterface::get($name, $default)` and `has($name)` read from the **incoming** request (same source as `Request::cookies`). The jar is built from `Request` in `initSessionAndCookies`.

### Writing cookies

- **CookieJar**: use the same jar to **queue** cookies for the response:
  - `set($name, $value, $options)` — options: `path`, `domain`, `maxAge`, `expires`, `secure`, `httpOnly`, `sameSite` (`lax` / `strict` / `none`).
  - `remove($name, $path = '/', $domain = null)` — sends the cookie with past expiry so the browser deletes it.
- The framework calls `getSetCookieLines()` and adds them to the response as `Set-Cookie` headers. The Swoole server sends each line via `parseSetCookieLineAndSend()` → `rawcookie()` so multiple cookies are sent correctly.

**Important**: Handlers must receive the **same** `CookieJar` instance that the framework uses when building the response. That is guaranteed by request-scoped constructor overrides (see below).

### CookieJar contract

- **Interface**: `Semitexa\Core\Cookie\CookieJarInterface`
- **Implementation**: `Semitexa\Core\Cookie\CookieJar`

Methods: `get`, `has`, `set`, `remove`, `getSetCookieLines()`.

---

## Request-scoped Session and CookieJar

Session, CookieJar, and Request are **per-request** and must be the same instances for the whole pipeline:

1. **Application** (`initSessionAndCookies`): creates `Session` and `CookieJar($request)`, puts them (and `Request`) into `RequestScopedContainer` via `set()`.
2. **Handlers**: when the container creates a handler (or any request-scoped service), it uses **constructor parameter overrides** for `SessionInterface`, `CookieJarInterface`, and `Request`: `RequestScopedContainer::getRequestScopedConstructorOverrides()` reads the constructor by reflection and injects the instances from the request-scoped cache. So the handler’s `CookieJar` is exactly the one that `finalizeSessionAndCookies` later uses — cookies set in the handler are included in the response.
3. **After each request**: `RequestScopedContainer::reset()` is called (e.g. in `server.php` `finally`), clearing the cache so the next request gets new Session/CookieJar/Request.

DI bindings for `SessionInterface`, `CookieJarInterface`, and `Request` in the global container use factories that delegate to `getRequestScoped()->get(...)`. The override mechanism ensures that even when the global container is used to construct handlers, they still receive the request-scoped instances.

---

## Response path: from CookieJar to browser

1. **Application**  
   `finalizeSessionAndCookies()` adds the session cookie to the CookieJar, then calls `$response->withHeaders(['Set-Cookie' => $cookieJar->getSetCookieLines()])`.

2. **Response**  
   Headers are stored as returned by `getHeaders()` (key may be `Set-Cookie` or `set-cookie`; comparison is case-insensitive where needed).

3. **server.php**  
   - Reads `Set-Cookie` from the response headers (array of lines).
   - Sends each line with `parseSetCookieLineAndSend($response, $line)`, which parses `name=value; Path=...; Max-Age=...` and calls Swoole’s `rawcookie()` so every cookie is sent separately.
   - Then sends status and other headers (skipping `Set-Cookie` to avoid duplication).

So: CookieJar → `getSetCookieLines()` → response headers → server → `rawcookie()` per line → browser.

---

## Summary: key files

| Area | File |
|------|------|
| Session interface | `src/Session/SessionInterface.php` |
| Session implementation | `src/Session/Session.php` |
| Session storage | `src/Session/SwooleTableSessionHandler.php`, `src/Session/SwooleSessionTableHolder.php` |
| Session segment attribute | `src/Session/Attribute/SessionSegment.php` |
| Cookie jar interface | `src/Cookie/CookieJarInterface.php` |
| Cookie jar implementation | `src/Cookie/CookieJar.php` |
| Init/finalize session and cookies | `src/Application.php` (`initSessionAndCookies`, `finalizeSessionAndCookies`) |
| Request-scoped overrides | `src/Container/RequestScopedContainer.php` (`getRequestScopedConstructorOverrides`) |
| Request cookies (parsing) | `src/RequestFactory.php` (Cookie header + Swoole cookie merge) |
| Sending Set-Cookie in Swoole | `server.php` (`parseSetCookieLineAndSend`, request callback) |
| Session table setup | `server.php` (Table create + `SwooleSessionTableHolder::setTable`) |

Debug logging (optional): `Semitexa\Core\Debug\SessionDebugLog` writes to `var/log/session-debug.log`; can be disabled or reduced in production.
