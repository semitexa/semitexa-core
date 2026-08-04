<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Pipeline\RequestTracerInterface;
use Semitexa\Core\Pipeline\SafeRequestTracer;

/**
 * The two promises {@see RequestTracerInterface} makes and cannot keep on its own.
 */
final class SafeRequestTracerTest extends TestCase
{
    #[Test]
    public function no_tracer_stays_no_tracer(): void
    {
        self::assertNull(SafeRequestTracer::wrap(null));
    }

    #[Test]
    public function wrapping_a_wrapper_returns_it_unchanged(): void
    {
        $wrapped = SafeRequestTracer::wrap(new RecordingTracer());

        self::assertSame($wrapped, SafeRequestTracer::wrap($wrapped));
    }

    #[Test]
    public function a_throwing_tracer_cannot_break_the_request(): void
    {
        $tracer = SafeRequestTracer::wrap(new ThrowingTracer());
        self::assertNotNull($tracer);

        $tracer->begin('request');
        $tracer->mark('anything');
        $tracer->end('request');

        // Reaching here is the assertion: any of the three would otherwise have
        // escaped onto the request path.
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_stops_calling_a_tracer_that_has_already_thrown(): void
    {
        $inner = new ThrowingTracer();
        $tracer = SafeRequestTracer::wrap($inner);
        self::assertNotNull($tracer);

        $tracer->begin('request');
        $tracer->begin('pipeline');
        $tracer->end('pipeline');

        self::assertSame(1, $inner->calls, 'the wrapper kept calling a tracer that faults');
    }

    #[Test]
    public function calls_reach_the_wrapped_tracer_unchanged(): void
    {
        $inner = new RecordingTracer();
        $tracer = SafeRequestTracer::wrap($inner);
        self::assertNotNull($tracer);

        $tracer->begin('request', ['path' => '/x']);
        $tracer->mark('decision', ['why' => 'because']);
        $tracer->end('request', ['status' => 200]);

        self::assertSame([
            ['begin', 'request', ['path' => '/x']],
            ['mark', 'decision', ['why' => 'because']],
            ['end', 'request', ['status' => 200]],
        ], $inner->events);
    }

    /**
     * The failing-request shape: hydration opens a span, throws, and the root span
     * closes in RouteExecutor's `finally` on top of it.
     */
    #[Test]
    public function a_span_left_open_by_an_exception_is_closed_when_its_parent_ends(): void
    {
        $inner = new RecordingTracer();
        $tracer = SafeRequestTracer::wrap($inner);
        self::assertNotNull($tracer);

        $tracer->begin('request');
        $tracer->begin('payload.hydrate_and_validate');
        // no end() — the exception skipped it
        $tracer->end('request');

        self::assertSame([
            ['begin', 'request', []],
            ['begin', 'payload.hydrate_and_validate', []],
            ['end', 'payload.hydrate_and_validate', ['unfinished' => true]],
            ['end', 'request', []],
        ], $inner->events);
    }

    #[Test]
    public function several_spans_left_open_close_innermost_first(): void
    {
        $inner = new RecordingTracer();
        $tracer = SafeRequestTracer::wrap($inner);
        self::assertNotNull($tracer);

        $tracer->begin('request');
        $tracer->begin('pipeline');
        $tracer->begin('handler');
        $tracer->end('request');

        self::assertSame(
            ['handler', 'pipeline', 'request'],
            array_values(array_map(
                static fn (array $e): string => $e[1],
                array_filter($inner->events, static fn (array $e): bool => $e[0] === 'end'),
            )),
        );
    }

    #[Test]
    public function a_span_that_closes_normally_is_not_marked_unfinished(): void
    {
        $inner = new RecordingTracer();
        $tracer = SafeRequestTracer::wrap($inner);
        self::assertNotNull($tracer);

        $tracer->begin('request');
        $tracer->begin('pipeline');
        $tracer->end('pipeline', ['handler' => 'X']);
        $tracer->end('request');

        self::assertSame(['end', 'pipeline', ['handler' => 'X']], $inner->events[2]);
    }

    /**
     * A name can nest inside itself — a re-run of the handler chain does exactly
     * that. Closing the outer occurrence would throw away the inner span's work.
     */
    #[Test]
    public function a_span_nested_inside_a_span_of_the_same_name_closes_the_inner_one(): void
    {
        $inner = new RecordingTracer();
        $tracer = SafeRequestTracer::wrap($inner);
        self::assertNotNull($tracer);

        $tracer->begin('pipeline');
        $tracer->begin('pipeline');
        $tracer->end('pipeline', ['which' => 'inner']);
        $tracer->end('pipeline', ['which' => 'outer']);

        self::assertSame([
            ['begin', 'pipeline', []],
            ['begin', 'pipeline', []],
            ['end', 'pipeline', ['which' => 'inner']],
            ['end', 'pipeline', ['which' => 'outer']],
        ], $inner->events);
    }

    #[Test]
    public function an_end_for_a_span_that_never_opened_is_passed_through_untouched(): void
    {
        $inner = new RecordingTracer();
        $tracer = SafeRequestTracer::wrap($inner);
        self::assertNotNull($tracer);

        $tracer->end('sse');

        self::assertSame([['end', 'sse', []]], $inner->events);
    }
}

final class RecordingTracer implements RequestTracerInterface
{
    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    public array $events = [];

    public function begin(string $name, array $context = []): void
    {
        $this->events[] = ['begin', $name, $context];
    }

    public function end(string $name, array $context = []): void
    {
        $this->events[] = ['end', $name, $context];
    }

    public function mark(string $name, array $context = []): void
    {
        $this->events[] = ['mark', $name, $context];
    }
}

final class ThrowingTracer implements RequestTracerInterface
{
    public int $calls = 0;

    public function begin(string $name, array $context = []): void
    {
        $this->calls++;

        throw new \RuntimeException('tracer is broken');
    }

    public function end(string $name, array $context = []): void
    {
        $this->calls++;

        throw new \RuntimeException('tracer is broken');
    }

    public function mark(string $name, array $context = []): void
    {
        $this->calls++;

        throw new \RuntimeException('tracer is broken');
    }
}
