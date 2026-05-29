<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

/**
 * Swoole-free port for writing Server-Sent-Event frames to a held stream.
 *
 * Mirrors the {@see \Semitexa\Core\Http\ResponseEmitterInterface} convention:
 * the transport-specific connection handle is typed `mixed` (an opaque stream,
 * e.g. a `Swoole\Http\Response`) so no runtime type leaks into the `core`
 * contract; the concrete adapter casts it internally. The boolean return is
 * the disconnect signal — `false` means the socket is gone and the caller
 * should treat the stream as closed.
 *
 * The port carries a fully-composed {@see SseFrame}: any event-name policy a
 * consumer enforces (the SSR `UiSseEventType` allow-list, a future multi-modal
 * emission policy) is applied on the consumer's own boundary BEFORE the frame
 * reaches this contract. The transport writes whatever frame it is given.
 */
interface SseTransportInterface
{
    /**
     * Write one composed SSE frame to a held stream.
     *
     * @param mixed $stream The opaque transport handle (e.g. Swoole\Http\Response).
     * @return bool `false` when the socket is gone (disconnect signal).
     */
    public function writeFrame(mixed $stream, SseFrame $frame): bool;

    /**
     * Write an inert SSE keepalive comment (`":\n\n"`). Per the SSE spec a line
     * beginning with ":" is a comment EventSource ignores, so no client-side
     * handling is required.
     *
     * @param mixed $stream The opaque transport handle (e.g. Swoole\Http\Response).
     * @return bool `false` when the socket is gone (disconnect signal).
     */
    public function writeComment(mixed $stream): bool;
}
