<?php

declare(strict_types=1);

namespace Semitexa\Core\Session;

use Semitexa\Core\Session\Attribute\SessionSegment;
use Semitexa\Core\Support\DtoSerializer;

/**
 * Session implementation: in-memory bag + Swoole Table persistence.
 * Supports typed payloads via getPayload/setPayload (class must have #[SessionSegment('name')]).
 */
final class Session implements SessionInterface
{
    private array $data = [];
    private array $flash = [];
    private array $flashNext = [];
    private bool $regenerate = false;

    public function __construct(
        private string $id,
        private SwooleTableSessionHandler $handler,
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

    public function getPayload(string $payloadClass): object
    {
        $segment = $this->getSegmentName($payloadClass);
        $arr = $this->data[$segment] ?? [];
        $dto = new $payloadClass();
        if ($arr !== []) {
            DtoSerializer::hydrate($dto, $arr);
        }
        return $dto;
    }

    public function setPayload(object $payload): void
    {
        $segment = $this->getSegmentName($payload::class);
        $this->data[$segment] = DtoSerializer::toArray($payload);
    }

    public function save(): void
    {
        $data = $this->data;
        $data['__flash__'] = $this->flashNext;

        if ($this->regenerate && SwooleSessionTableHolder::hasTable()) {
            $this->handler->destroy($this->id);
            $this->id = $this->generateId();
            $this->regenerate = false;
        }

        $this->handler->write($this->id, $data, $this->lifetimeSeconds);
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
        $ref = new \ReflectionClass($payloadClass);
        $attrs = $ref->getAttributes(SessionSegment::class);
        if ($attrs === []) {
            throw new \InvalidArgumentException("Session payload class {$payloadClass} must have #[SessionSegment('name')] attribute.");
        }
        return $attrs[0]->newInstance()->segment;
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
