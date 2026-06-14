<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

use Semitexa\Core\Log\StaticLoggerBridge;

/**
 * Portable, transport-neutral SSE wire frame.
 *
 * This is the generic frame-composition primitive the SSE transport hands to
 * a held stream: an optional `id`, an already-resolved `event` name, and the
 * JSON `data` body. It has ZERO knowledge of any consumer's event vocabulary
 * (e.g. the SSR `UiSseEventType` allow-list) — by the time a frame reaches
 * this value object its `event` has already been resolved/validated on the
 * consumer's own boundary. {@see toWire()} is the single place mechanical SSE
 * byte composition happens; it lives in `core` because it is pure
 * array→string with no Swoole or domain dependency.
 *
 * The chokepoint that maps a consumer's typed `_type` field to a *validated*
 * `event` name does NOT live here — it stays with the consumer (the SSR
 * `resolveSseEventName()` + `UiSseEventType` path), which constructs the frame
 * only after the allow-list check. A `core` lifecycle frame (connected/close)
 * supplies its own closed-set transport event name directly. Either way, an
 * arbitrary event string cannot be promoted to a wire `event:` line here: this
 * object only renders the event it is given, CR/LF-stripped.
 */
final class SseFrame
{
    /**
     * @param array<array-key, mixed> $data The JSON body of the frame.
     */
    private function __construct(
        private readonly ?string $id,
        private readonly ?string $event,
        private readonly array $data,
    ) {
    }

    /**
     * Build a frame from an already-resolved (validated, if applicable) event
     * name. The `$event` is whatever the caller's resolution produced — this
     * object performs no allow-list check, only mechanical CR/LF hygiene when
     * rendering. `$data` is the full body and may still contain the `id`/`event`
     * keys; they are preserved verbatim in the JSON line.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromResolved(?string $id, ?string $event, array $data): self
    {
        return new self($id, $event, $data);
    }

    /**
     * Render this frame to its SSE wire representation:
     *
     *   id: <id>\n        (only when an id is present)
     *   event: <event>\n  (only when a non-empty event name is present)
     *   data: <json>\n\n
     *
     * CR/LF injection on the `id:` and `event:` lines is stripped. A JSON
     * encode failure (e.g. invalid UTF-8 in the body) falls back to an empty
     * object so a malformed/`false` frame never reaches the wire.
     */
    public function toWire(): string
    {
        $line = '';
        if ($this->id !== null) {
            $line .= 'id: ' . str_replace(["\r", "\n"], '', $this->id) . "\n";
        }
        if ($this->event !== null && $this->event !== '') {
            $line .= 'event: ' . str_replace(["\r", "\n"], '', $this->event) . "\n";
        }
        try {
            $json = json_encode(
                $this->data,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $e) {
            StaticLoggerBridge::warning('core', 'sse_payload_json_encode_failed', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
            $json = '{}';
        }
        $line .= 'data: ' . $json . "\n\n";

        return $line;
    }
}
