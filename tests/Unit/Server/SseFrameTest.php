<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Server\SseFrame;

/**
 * Byte-contract tests for the portable SSE frame primitive. These lock the
 * mechanical array→string composition that was relocated out of
 * `AsyncResourceSseServer::composeSseFrame()` into `core` so the wire shape
 * stays byte-identical. The event-name allow-list (`UiSseEventType`) is NOT
 * exercised here — it stays on the SSR consumer boundary; this object renders
 * whatever already-resolved event it is given (CR/LF-stripped).
 */
final class SseFrameTest extends TestCase
{
    #[Test]
    public function renders_id_event_and_data_lines_in_order(): void
    {
        $wire = SseFrame::fromResolved('42', 'ui.patch', ['id' => '42', 'patch' => ['v' => 1]])->toWire();

        self::assertSame(
            "id: 42\nevent: ui.patch\ndata: {\"id\":\"42\",\"patch\":{\"v\":1}}\n\n",
            $wire,
        );
    }

    #[Test]
    public function omits_id_line_when_id_is_null(): void
    {
        $wire = SseFrame::fromResolved(null, 'notification', ['level' => 'info'])->toWire();

        self::assertStringStartsWith("event: notification\n", $wire);
        self::assertStringNotContainsString('id: ', $wire);
    }

    #[Test]
    public function omits_event_line_when_event_is_null_or_empty(): void
    {
        $null = SseFrame::fromResolved(null, null, ['a' => 1])->toWire();
        $empty = SseFrame::fromResolved(null, '', ['a' => 1])->toWire();

        self::assertSame("data: {\"a\":1}\n\n", $null);
        self::assertSame("data: {\"a\":1}\n\n", $empty);
    }

    #[Test]
    public function strips_crlf_from_id_and_event_to_prevent_header_injection(): void
    {
        $wire = SseFrame::fromResolved("42\nevent: spoof", "ui.patch\ndata: stolen", ['x' => 1])->toWire();

        self::assertStringContainsString("id: 42event: spoof\n", $wire);
        self::assertStringContainsString("event: ui.patchdata: stolen\n", $wire);
        // Exactly one id line and one event line — no injected extras.
        self::assertSame(1, substr_count($wire, "id: "));
        self::assertSame(1, substr_count($wire, "\nevent: "));
    }

    #[Test]
    public function json_encode_failure_falls_back_to_empty_object(): void
    {
        // Invalid UTF-8 makes json_encode throw under JSON_THROW_ON_ERROR.
        $wire = SseFrame::fromResolved(null, 'ui.patch', ['bad' => "\xC3\x28"])->toWire();

        self::assertSame("event: ui.patch\ndata: {}\n\n", $wire);
    }

    #[Test]
    public function frame_always_ends_with_blank_line_per_sse_protocol(): void
    {
        $wire = SseFrame::fromResolved(null, null, [])->toWire();

        self::assertStringEndsWith("\n\n", $wire);
        self::assertSame("data: []\n\n", $wire);
    }

    #[Test]
    public function unicode_and_slashes_are_left_unescaped(): void
    {
        $wire = SseFrame::fromResolved(null, null, ['url' => 'https://x/y', 'msg' => 'café'])->toWire();

        self::assertStringContainsString('"url":"https://x/y"', $wire);
        self::assertStringContainsString('"msg":"café"', $wire);
    }
}
