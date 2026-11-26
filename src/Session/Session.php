<?php

declare(strict_types=1);

namespace Semitexa\Core\Session;

use Semitexa\Core\Attribute\WorkerState;
use Semitexa\Core\Csrf\CsrfToken;
use Semitexa\Core\Session\Attribute\SessionSegment;
use Semitexa\Core\Support\PayloadSerializer;

/**
 * Session implementation: in-memory bag + backend persistence (Swoole Table or Redis).
 * Supports typed payloads via getPayload/setPayload (class must have #[SessionSegment('name')]).
 */
final class Session implements SessionInterface
{
    private array $data = [];
    private array $flash = [];
    private array $flashNext = [];
    /**
     * The id regenerate() replaced. On save() its stored data is replaced by a
     * short-lived marker ({@see RETIRED_MARKER}) when the store holds it, and
     * destroyed when it never did.
     */
    private ?string $retiredId = null;
    /** Whether the id this session was built with is one regenerate() retired moments ago. */
    private bool $openedOnRetiredId = false;
    /**
     * Whether save() found that the id this session was built with is no
     * longer a session in the store: another request retired it (or it ran
     * out) while this one was on its way.
     */
    private bool $retiredMeanwhile = false;
    private bool $regenerated = false;
    /** Whether the store returned data for the id this session was built with. */
    private bool $heldByStore;
    /**
     * Whether this request gave the session something to keep or showed it to
     * the client: a write, or a read of its id or its CSRF token. A session
     * the store does not hold and nothing used is not worth a row
     * ({@see isWorthStoring()}).
     */
    private bool $used = false;
    private const COOKIE_ISSUED_AT_KEY = '__cookie_at__';
    /** When the session cookie (and XSRF-TOKEN) for this id was last sent to the browser. */
    private ?int $cookieIssuedAt = null;
    /** The CSRF token the stored session held when opened, '' when it had none. */
    private string $storedCsrfToken = '';

    /**
     * The one shape a session id has: what {@see generateId()} mints, 32
     * lowercase hex characters. `D` keeps `$` from matching before a trailing
     * newline.
     */
    private const ID_PATTERN = '/^[0-9a-f]{32}$/D';

    /**
     * What the store holds under an id regenerate() retired, for
     * {@see RETIRED_ID_GRACE_SECONDS}: this and nothing else. It says "this id
     * was replaced a moment ago". It carries none of the session's data and
     * does not name the id that replaced it, so whoever knew the id from
     * before a sign-in learns and reaches nothing through it (CWE-384). A
     * saved session always carries `__flash__`, so no session equals it.
     *
     * Public for the stores: a store that makes room by evicting rows leaves
     * a marker alone ({@see SwooleTableSessionHandler}), as it leaves by
     * itself within seconds and its id is one a browser may still present.
     */
    public const RETIRED_MARKER = ['__retired__' => 1];

    /**
     * How long a retired id stays marked. A browser sends the old cookie only
     * on requests that left before it processed the sign-in's response (a
     * poll, a stream reconnecting), so the marker has to outlive one such
     * request's way to a worker: a slow mobile round trip plus a queue, not a
     * session. Ten seconds covers that. Kept short because a browser that
     * never received the new cookie gets no cookie in answer to the old one
     * while the marker lives, and so stays a guest that cannot start a session
     * for that long. The marker costs nothing more: it replaces the old row in
     * place, and it is the first row the Swoole table reclaims.
     */
    public const RETIRED_ID_GRACE_SECONDS = 10;

    /**
     * #[SessionSegment] name per payload class. A class's attributes cannot
     * change inside a worker, so the reflection runs once per class rather
     * than on every getPayload()/setPayload() (several per request).
     *
     * @var array<class-string, string>
     */
    #[WorkerState('#[SessionSegment] name keyed by payload class; derived from code only.')]
    private static array $segmentNames = [];

    public function __construct(
        private string $id,
        private SessionHandlerInterface $handler,
        private string $cookieName,
        private int $lifetimeSeconds = 3600,
    ) {
        $this->data = $handler->read($id);
        if ($this->data === self::RETIRED_MARKER) {
            // Not a session: nothing is read from it, and nothing is held.
            $this->openedOnRetiredId = true;
            $this->data = [];
        }
        $this->heldByStore = $this->data !== [];
        $this->flash = $this->data['__flash__'] ?? [];
        unset($this->data['__flash__']);
        $cookieIssuedAt = $this->data[self::COOKIE_ISSUED_AT_KEY] ?? null;
        $this->cookieIssuedAt = is_int($cookieIssuedAt) ? $cookieIssuedAt : null;
        unset($this->data[self::COOKIE_ISSUED_AT_KEY]);
        $this->storedCsrfToken = $this->heldByStore ? $this->csrfTokenForCheck() : '';
    }

    /**
     * The session for an id a client presented (its cookie), in strict mode:
     * the id is used only when it has the shape this class mints AND the store
     * holds a session under it. Anything else gets a fresh server-made id, so a
     * client cannot choose the id a session lives under (session fixation,
     * CWE-384).
     *
     * "Holds" is the read this constructor does anyway: every saved session
     * carries at least its `__flash__` key, so an empty read is an id the store
     * does not know (never issued, expired, evicted). No second store call, and
     * nothing is created for an unknown id.
     *
     * The fresh id is written by save() like any other, and the phase sends
     * its cookie only after that write: a browser never holds an id the store
     * has not seen.
     *
     * An id regenerate() retired a moment ago is not held either: its session
     * starts empty under a fresh id, and {@see isOnRetiredId()} tells
     * the phase to leave the browser's cookies alone.
     *
     * @throws \Throwable whatever the handler's read() throws (store unreadable)
     */
    public static function open(
        string $presentedId,
        SessionHandlerInterface $handler,
        string $cookieName,
        int $lifetimeSeconds = 3600,
    ): self {
        if (!self::isWellFormedId($presentedId)) {
            return new self(self::generateId(), $handler, $cookieName, $lifetimeSeconds);
        }

        $session = new self($presentedId, $handler, $cookieName, $lifetimeSeconds);
        if (!$session->heldByStore) {
            $session->id = self::generateId();
        }

        return $session;
    }

    /** A new session id: 128 random bits as 32 lowercase hex characters. */
    public static function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Whether $id has the shape {@see generateId()} produces. Says nothing about the store. */
    public static function isWellFormedId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->used = true;
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function remove(string $key): void
    {
        $this->used = true;
        unset($this->data[$key]);
    }

    /** Alias for remove() for compatibility with auth handlers. */
    public function forget(string $key): void
    {
        $this->remove($key);
    }

    public function clear(): void
    {
        $this->used = true;
        $this->data = [];
        $this->flash = [];
        $this->flashNext = [];
    }

    /**
     * Counts as use: whoever asks for the id binds something to it (a signed
     * UI context, a state key), and that holds only when the browser is sent
     * the same id. The phase reads it through getSessionIdForCookie().
     */
    public function getId(): string
    {
        $this->used = true;

        return $this->id;
    }

    public function regenerate(): void
    {
        // The new id takes effect now, not on save(): anything bound to the
        // session later in this request (signed UI contexts, UI state keys)
        // must carry the id the browser presents next. Taken at save() it
        // left them on the old id, and the next request refused them.
        $this->retiredId ??= $this->id;
        $this->regenerated = true;
        $this->used = true;
        $this->cookieIssuedAt = null;
        $this->id = self::generateId();
        // The CSRF token must not survive a privilege change: under session fixation
        // an attacker who read the token before login would otherwise still hold a
        // valid one afterwards. Rotated here rather than in save() so anything
        // rendered later in this request, and the XSRF-TOKEN cookie emitted in
        // SessionPhase::finalize(), already carry the new value.
        $this->setPayload(CsrfToken::generate());
    }

    /**
     * True once regenerate() ran in this request: a sign-in or sign-out whose
     * result lives only in the session about to be saved.
     */
    public function wasRegenerated(): bool
    {
        return $this->regenerated;
    }

    public function flash(string $key, mixed $value): void
    {
        $this->used = true;
        $this->flashNext[$key] = $value;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        $v = $this->flash[$key] ?? $default;
        unset($this->flash[$key]);
        return $v;
    }

    /**
     * @template T of object
     * @param class-string<T> $payloadClass
     * @return T
     */
    public function getPayload(string $payloadClass): object
    {
        // A CSRF token that was read is on its way to the client, in a form
        // field or a header: the session it belongs to has to exist when it
        // comes back.
        if ($payloadClass === CsrfToken::class) {
            $this->used = true;
        }
        $segment = $this->getSegmentName($payloadClass);
        $arr = $this->data[$segment] ?? [];
        $dto = new $payloadClass();
        if (is_array($arr) && $arr !== []) {
            /** @var array<string, mixed> $arr */
            PayloadSerializer::hydrate($dto, $arr);
        }

        return $dto;
    }

    public function setPayload(object $payload): void
    {
        $this->used = true;
        $segment = $this->getSegmentName($payload::class);
        $this->data[$segment] = PayloadSerializer::toArray($payload);
    }

    /**
     * Writes the session, unless the id it was opened with stopped being a
     * session while this request ran.
     *
     * An id the store held is one a browser holds, and this request is not
     * the only one that presented it. A sign-in or a sign-out that saved
     * between this request's read and this save has retired the id, and a
     * blind write here put the old session back under it with a full
     * lifetime: after a sign-in the visitor was signed out again (the old
     * cookie was re-sent with it), and after a sign-out the signed-in session
     * was back for whoever held the old cookie. So a held id is written, and
     * retired, only while the store still holds a session under it
     * ({@see writeWhileHeld()}). When it does not, nothing is written, and
     * {@see isOnRetiredId()} says so to the phase.
     *
     * An id the store never held was never sent to a browser: no other
     * request can have it, and it is written as it is.
     *
     * A session opened on an id that was already retired writes nothing
     * either: it is sent no cookie, so no browser would ever ask for its row.
     */
    public function save(): void
    {
        if ($this->openedOnRetiredId || $this->retiredMeanwhile) {
            return;
        }

        $data = $this->data;
        $data['__flash__'] = $this->flashNext;
        if ($this->cookieIssuedAt !== null) {
            $data[self::COOKIE_ISSUED_AT_KEY] = $this->cookieIssuedAt;
        }

        if (!$this->heldByStore) {
            if ($this->retiredId !== null) {
                $this->handler->destroy($this->retiredId);
                $this->retiredId = null;
            }
            $this->handler->write($this->id, $data, $this->lifetimeSeconds);

            return;
        }

        /** @var array<string, mixed> $data */
        if ($this->retiredId === null) {
            $this->retiredMeanwhile = !$this->writeWhileHeld($this->id, $data, $this->lifetimeSeconds);

            return;
        }

        // The new id first, the marker after. The other way round, a full
        // Swoole table made room for the new id by deleting the row that
        // expires soonest: the marker just written. And a new id that finds
        // no room now leaves the old session as it was.
        $this->handler->write($this->id, $data, $this->lifetimeSeconds);
        if (!$this->writeWhileHeld($this->retiredId, self::RETIRED_MARKER, self::RETIRED_ID_GRACE_SECONDS)) {
            // Another request retired the id first: this one came second, and
            // its session is built on data that request has already replaced.
            // (An old id that ran out, or that a full table evicted to make
            // room for the write above, reads the same: the phase answers a
            // sign-in with "try again", and the retry starts clean.)
            $this->handler->destroy($this->id);
            $this->retiredMeanwhile = true;

            return;
        }
        $this->retiredId = null;
    }

    /**
     * Writes $data under $id when the store still holds a session under it:
     * not when the id is gone, and not when it is marked as retired. Both
     * writes of an id a browser holds go through here: the session's own
     * data, and the marker that retires it.
     *
     * Retiring marks the id instead of deleting it because requests the
     * browser sent before it received the new cookie are still on their way.
     * Deleted, the id was unknown to them, their response expired "the
     * unknown cookie", and reaching the browser after the sign-in's, that
     * dropped the new session cookie. The marker is written with the store's
     * own expiry, so it is gone by itself.
     *
     * One step where the store has one ({@see HeldSessionWriterInterface}:
     * a script in Redis, a lock all workers share on the Swoole table).
     * Elsewhere a read and then a write, and a retirement
     * that lands between the two is written over.
     *
     * @param array<string, mixed> $data
     */
    private function writeWhileHeld(string $id, array $data, int $lifetimeSeconds): bool
    {
        if ($this->handler instanceof HeldSessionWriterInterface) {
            return $this->handler->writeIfHeld($id, $data, $lifetimeSeconds, self::RETIRED_MARKER);
        }

        $held = $this->handler->read($id);
        if ($held === [] || $held === self::RETIRED_MARKER) {
            return false;
        }
        $this->handler->write($id, $data, $lifetimeSeconds);

        return true;
    }

    /**
     * Gives the session its CSRF token when it has none, without that counting
     * as use: every session has a token, and one nobody read is no reason to
     * store the session.
     *
     * @internal for SessionPhase
     */
    public function ensureCsrfToken(): void
    {
        if ($this->csrfTokenForCheck() !== '') {
            return;
        }
        $this->data[$this->getSegmentName(CsrfToken::class)] = PayloadSerializer::toArray(CsrfToken::generate());
    }

    /**
     * The session's CSRF token for comparing with a submitted one, '' when it
     * has none. Not a use, unlike getPayload(CsrfToken::class): a check sends
     * the token nowhere, and a refused POST is no reason to store a session
     * (a POST with an invented cookie and no token left a row per request).
     *
     * @internal for SessionPhase and CsrfListener
     */
    public function csrfTokenForCheck(): string
    {
        $raw = $this->data[$this->getSegmentName(CsrfToken::class)] ?? null;
        $value = is_array($raw) ? ($raw['value'] ?? '') : '';

        return is_string($value) ? $value : '';
    }

    /**
     * Whether the end of the request should write this session and send its
     * cookies: the store already holds it (so its lifetime keeps sliding), or
     * this request used it (see {@see $used}). False for the session of a
     * visitor who presented none and whose request touched none: an error
     * page, a machine route, a page without a form. Stored, each of those was
     * a row per request for whoever asked, and on the Swoole table a flood of
     * them evicted the sessions of signed-in visitors.
     *
     * save() itself stays unconditional.
     */
    public function isWorthStoring(): bool
    {
        return $this->heldByStore || $this->used;
    }

    /**
     * True when the id the request presented is one another request retired.
     *
     * Either it was already marked when this request opened it (within the
     * last {@see RETIRED_ID_GRACE_SECONDS}: the request left the browser
     * before the sign-in's response arrived), and it was served as a guest on
     * an empty session. Or it was a session then and is none by the time of
     * save(): the request was served with what it read, and save() wrote
     * nothing. Both ways the phase sends no session cookie, so this response
     * cannot replace the cookie the other request set, and nothing of this
     * session is in the store.
     *
     * @internal for SessionPhase
     */
    public function isOnRetiredId(): bool
    {
        return $this->openedOnRetiredId || $this->retiredMeanwhile;
    }

    /**
     * False for a request served on a {@see TransientSessionHandler} because
     * the real store could not be read: nothing it holds is kept.
     */
    public function persists(): bool
    {
        return !$this->handler instanceof TransientSessionHandler;
    }

    public function setHandler(SessionHandlerInterface $handler): void
    {
        $this->handler = $handler;
    }

    public function getCookieName(): string
    {
        return $this->cookieName;
    }

    public function getSessionIdForCookie(): string
    {
        return $this->id;
    }

    /**
     * Whether the response should send the session cookie (and XSRF-TOKEN),
     * stamping the cookie's issue time for {@see save()} when it should.
     *
     * Sent when the browser does not yet hold this id, when the session's CSRF
     * token changed (or the request presented a different XSRF-TOKEN), or once
     * the cookie in the browser is past half its lifetime. Not re-sent on an
     * ordinary request that already carried this id: a response that saved
     * before a sign-in retired its id and reaches the browser after the
     * sign-in's response then sets no cookie, and cannot put the retired cookie
     * back in the browser.
     *
     * @internal for SessionPhase
     */
    public function refreshCookieIfDue(string $presentedId, string $presentedXsrfToken = '', ?int $now = null): bool
    {
        $now ??= time();
        $csrf = $this->csrfTokenForCheck();
        $sameCookie = !$this->regenerated
            && $this->heldByStore
            && $presentedId === $this->id
            && $this->storedCsrfToken !== ''
            && $csrf === $this->storedCsrfToken
            && ($presentedXsrfToken === '' || $presentedXsrfToken === $csrf);

        if ($sameCookie) {
            if ($this->cookieIssuedAt === null) {
                $this->cookieIssuedAt = $now;

                return false;
            }
            if ($this->lifetimeSeconds > 0 && ($now - $this->cookieIssuedAt) <= intdiv($this->lifetimeSeconds, 2)) {
                return false;
            }
        }

        $this->cookieIssuedAt = $now;

        return true;
    }

    /** @param class-string $payloadClass */
    private function getSegmentName(string $payloadClass): string
    {
        if (isset(self::$segmentNames[$payloadClass])) {
            return self::$segmentNames[$payloadClass];
        }

        $ref = new \ReflectionClass($payloadClass);
        $attrs = $ref->getAttributes(SessionSegment::class);
        if ($attrs === []) {
            throw new \InvalidArgumentException("Session payload class {$payloadClass} must have #[SessionSegment('name')] attribute.");
        }
        return self::$segmentNames[$payloadClass] = $attrs[0]->newInstance()->segment;
    }
}
