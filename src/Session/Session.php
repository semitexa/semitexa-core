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
     * short-lived marker ({@see RETIRED_MARKER}) when the store held it, and
     * destroyed otherwise.
     */
    private ?string $retiredId = null;
    /** Whether the id this session was built with is one regenerate() retired moments ago. */
    private bool $openedOnRetiredId = false;
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
     */
    private const RETIRED_MARKER = ['__retired__' => 1];

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
     * starts empty under a fresh id, and {@see wasOpenedOnRetiredId()} tells
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

        return $this->hydratePayload($payloadClass);
    }

    /**
     * @template T of object
     * @param class-string<T> $payloadClass
     * @return T
     */
    private function hydratePayload(string $payloadClass): object
    {
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

    public function save(): void
    {
        $data = $this->data;
        $data['__flash__'] = $this->flashNext;

        if ($this->retiredId !== null) {
            $this->retire($this->retiredId);
            $this->retiredId = null;
        }

        $this->handler->write($this->id, $data, $this->lifetimeSeconds);
    }

    /**
     * Takes the data of the id regenerate() replaced out of the store.
     *
     * An id the store held is one a browser holds, and requests it sent before
     * it received the new cookie are still on their way. Destroyed at once,
     * the id was unknown to them, their response expired "the unknown cookie",
     * and when that response reached the browser after the sign-in's, it
     * dropped the new session cookie: signed out a moment after signing in.
     * So the id is marked for a few seconds instead, and a request presenting
     * a marked id is answered without any session cookie.
     *
     * The marker is written with the store's own expiry, so it is gone by
     * itself. An id the store did not hold was never sent to a browser:
     * nothing to mark.
     */
    private function retire(string $retiredId): void
    {
        if (!$this->heldByStore) {
            $this->handler->destroy($retiredId);

            return;
        }

        try {
            $this->handler->write($retiredId, self::RETIRED_MARKER, self::RETIRED_ID_GRACE_SECONDS);
        } catch (SessionStoreFullException) {
            // The row was evicted since this request read it and the table has
            // no room: the old data is gone either way, and the marker is not
            // worth failing the sign-in.
            $this->handler->destroy($retiredId);
        }
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
        if ($this->hydratePayload(CsrfToken::class)->getValue() !== '') {
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
     * @internal for CsrfListener
     */
    public function csrfTokenForCheck(): string
    {
        return $this->hydratePayload(CsrfToken::class)->getValue();
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
     * True when the request presented an id that regenerate() retired within
     * the last {@see RETIRED_ID_GRACE_SECONDS}: a request that left the
     * browser before the sign-in's response arrived. It is served as a guest
     * on an empty session, and the phase neither stores that session nor sends
     * any session cookie for it, so its response cannot replace the cookie the
     * sign-in set.
     *
     * @internal for SessionPhase
     */
    public function wasOpenedOnRetiredId(): bool
    {
        return $this->openedOnRetiredId;
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
