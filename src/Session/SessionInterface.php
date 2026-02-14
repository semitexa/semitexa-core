<?php

declare(strict_types=1);

namespace Semitexa\Core\Session;

/**
 * Session contract. Prefer typed access via session payloads (getPayload/setPayload).
 */
interface SessionInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function has(string $key): bool;

    public function remove(string $key): void;

    public function clear(): void;

    public function getId(): string;

    /** Generate new session id and keep data. Call after login to prevent fixation. */
    public function regenerate(): void;

    /** One-time message for next request. */
    public function flash(string $key, mixed $value): void;

    /** Get and consume flash message. */
    public function getFlash(string $key, mixed $default = null): mixed;

    /**
     * Get session segment as typed payload. Class must be attributed with #[SessionSegment('name')].
     */
    public function getPayload(string $payloadClass): object;

    /**
     * Persist payload into session segment. Class must be attributed with #[SessionSegment('name')].
     */
    public function setPayload(object $payload): void;

    /** Persist in-memory data to storage (e.g. Swoole Table). Called by framework at end of request. */
    public function save(): void;
}
