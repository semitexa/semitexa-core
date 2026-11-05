<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\BrokenContainerReport;
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
        self::assertSame(1, substr_count(rtrim($stdout), "\n") + 1);
        $verdict = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('fail', $verdict['verdict']);
        self::assertSame('container_not_built', $verdict['error']);
        self::assertSame('App\\Thing', $verdict['class']);
        self::assertSame('iri', $verdict['property']);
    }

    /**
     * @param list<string> $argv
     * @return array{0: int, 1: string, 2: string}
     */
    private function report(array $argv): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $code = (new BrokenContainerReport(new InjectionException(
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
