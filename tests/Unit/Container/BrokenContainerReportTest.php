<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\BrokenContainerReport;
use Semitexa\Core\Container\Exception\CircularDependencyException;
use Semitexa\Core\Container\Exception\InjectionException;

final class BrokenContainerReportTest extends TestCase
{
    #[Test]
    public function the_reader_gets_the_reason_and_a_failing_exit(): void
    {
        [$code, $stdout, $stderr] = $this->report(['bin/semitexa', 'lint:di']);

        self::assertSame(1, $code);
        self::assertSame('', $stdout, 'a person reads stderr; nothing here is output of the command');
        self::assertStringContainsString('Cannot inject App\\Thing::$iri', $stderr);
        self::assertStringNotContainsString('#0 ', $stderr, 'no stack trace');
    }

    #[Test]
    public function json_is_one_line_a_gate_can_read(): void
    {
        [$code, $stdout] = $this->report(['bin/semitexa', 'ai:verify', '--json']);

        self::assertSame(1, $code);
        self::assertStringEndsWith("\n", $stdout);
        self::assertSame(1, substr_count($stdout, "\n"), 'one line, ended once');
        $verdict = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('fail', $verdict['verdict']);
        self::assertSame('container_not_built', $verdict['error']);
        self::assertSame('App\\Thing', $verdict['class']);
        self::assertSame('iri', $verdict['property']);
    }

    #[Test]
    public function a_cycle_is_reported_the_same_way_with_no_class_to_name(): void
    {
        [$code, $stdout] = $this->report(['bin/semitexa', 'ai:verify', '--json'], new CircularDependencyException(['A', 'B', 'A'], 'Circular dependency: A -> B -> A'));

        self::assertSame(1, $code);
        $verdict = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('fail', $verdict['verdict']);
        self::assertSame('Circular dependency: A -> B -> A', $verdict['message']);
        self::assertArrayNotHasKey('class', $verdict);
    }

    #[Test]
    public function a_stray_byte_in_the_message_still_gives_a_verdict(): void
    {
        [$code, $stdout] = $this->report(['bin/semitexa', 'ai:verify', '--json'], new \RuntimeException("Bad name \xff here"));

        self::assertSame(1, $code);
        self::assertSame('fail', json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['verdict']);
    }

    /**
     * @param list<string> $argv
     * @return array{0: int, 1: string, 2: string}
     */
    private function report(array $argv, ?\Throwable $failure = null): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $code = (new BrokenContainerReport($failure ?? new InjectionException(
            targetClass: 'App\\Thing',
            propertyName: 'iri',
            propertyType: 'App\\Iri',
            injectionKind: 'readonly',
            message: 'Cannot inject App\\Thing::$iri (type: App\\Iri, kind: readonly). No binding found.',
        )))->write($argv, $stdout, $stderr);

        rewind($stdout);
        rewind($stderr);

        return [$code, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }
}
