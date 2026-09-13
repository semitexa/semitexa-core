<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\Contract\ExceptionResponseMapperInterface;
use Semitexa\Core\Discovery\ResolvedRouteMetadata;
use Semitexa\Core\Pipeline\RequestTracerInterface;
use Semitexa\Core\Container\RequestScopedContainer;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Pipeline\RouteExecutor;
use Semitexa\Core\Request;

/**
 * A gate declines by throwing, so a refusal and a crash left the pipeline the
 * same way and reached an observer as the same event. The mapped status is what
 * tells them apart, and it is published on the trace as
 * `request.exception.mapped`.
 *
 * Nothing asserted that mark, so removing it — or recording it with the wrong
 * status — would not have failed the suite. The PipelineExecutor tracing tests
 * cover the phases inside the pipeline; this one covers the branch above them.
 */
final class RouteExecutorExceptionTracingTest extends TestCase
{
    #[Test]
    public function a_mapped_exception_publishes_the_status_it_was_mapped_to(): void
    {
        $tracer = new ExceptionMarkRecordingTracer();

        $this->executeThrowing($tracer, new \RuntimeException('a gate declined'), 403);

        self::assertSame(
            ['status' => 403],
            $tracer->contextOf('request.exception.mapped'),
            'a refusal and a crash are the same event without this',
        );
    }

    #[Test]
    public function the_status_published_is_the_mapped_one_not_a_fixed_500(): void
    {
        $tracer = new ExceptionMarkRecordingTracer();

        $this->executeThrowing($tracer, new \RuntimeException('not found'), 404);

        self::assertSame(['status' => 404], $tracer->contextOf('request.exception.mapped'));
    }

    /**
     * Drive RouteExecutor far enough to reach the mapped-exception branch. The
     * route resolution is left to fail on purpose: whatever throws, the branch
     * under test is the one that maps it and marks the trace.
     */
    private function executeThrowing(ExceptionMarkRecordingTracer $tracer, \Throwable $thrown, int $status): void
    {
        $mapper = new class ($status) implements ExceptionResponseMapperInterface {
            public function __construct(private readonly int $status) {}

            public function map(\Throwable $e, Request $request, ResolvedRouteMetadata $metadata): HttpResponse
            {
                return new HttpResponse(content: 'mapped', statusCode: $this->status);
            }
        };

        $container = new ExceptionTracingContainer([
            RequestTracerInterface::class => $tracer,
            ExceptionResponseMapperInterface::class => $mapper,
        ]);

        $executor = new RouteExecutor(new RequestScopedContainer($container), $container);

        $route = new DiscoveredRoute(
            path: '/traced',
            methods: ['GET'],
            name: 'traced',
            requestClass: 'Semitexa\\Core\\Tests\\Unit\\Pipeline\\MissingOnPurposePayload',
            responseClass: null,
            handlers: [],
            type: 'json',
            transport: null,
            produces: null,
            consumes: null,
            module: 'Test',
        );

        // The payload class does not exist, so hydration throws inside the try —
        // which is the point: whatever the exception is, the branch under test
        // is the one that maps it and marks the trace with the mapped status.
        $executor->execute($route, new Request(
            method: 'GET',
            uri: '/traced',
            headers: [],
            query: [],
            post: [],
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/traced'],
            cookies: [],
        ));
    }
}

final class ExceptionMarkRecordingTracer implements RequestTracerInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $marks = [];

    public function begin(string $name, array $context = []): void {}

    public function end(string $name, array $context = []): void {}

    public function mark(string $name, array $context = []): void
    {
        $this->marks[$name] = $context;
    }

    /** @return array<string, mixed>|null */
    public function contextOf(string $mark): ?array
    {
        return $this->marks[$mark] ?? null;
    }
}

final class ExceptionTracingContainer implements ContainerInterface
{
    /** @param array<string, mixed> $entries */
    public function __construct(private array $entries = []) {}

    public function get(string $id): mixed
    {
        if (!isset($this->entries[$id])) {
            throw new class ("no entry {$id}") extends \RuntimeException implements NotFoundExceptionInterface {};
        }

        return $this->entries[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->entries[$id]);
    }
}
