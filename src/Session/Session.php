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
    private bool $regenerate = false;

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
        $this->flash = $this->data['__flash__'] ?? [];
        unset($this->data['__flash__']);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    /** Alias for remove() for compatibility with auth handlers. */
    public function forget(string $key): void
    {
        $this->remove($key);
    }

    public function clear(): void
    {
        $this->data = [];
        $this->flash = [];
        $this->flashNext = [];
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function regenerate(): void
    {
        $this->regenerate = true;
        // The CSRF token must not survive a privilege change: under session fixation
        // an attacker who read the token before login would otherwise still hold a
        // valid one afterwards. Rotated here rather than in save() so anything
        // rendered later in this request, and the XSRF-TOKEN cookie emitted in
        // SessionPhase::finalize(), already carry the new value.
        $this->setPayload(CsrfToken::generate());
    }

    public function flash(string $key, mixed $value): void
    {
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
        $segment = $this->getSegmentName($payload::class);
        $this->data[$segment] = PayloadSerializer::toArray($payload);
    }

    public function save(): void
    {
        $data = $this->data;
        $data['__flash__'] = $this->flashNext;

        if ($this->regenerate) {
            $this->handler->destroy($this->id);
            $this->id = $this->generateId();
            $this->regenerate = false;
        }

        $this->handler->write($this->id, $data, $this->lifetimeSeconds);
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

    private function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
