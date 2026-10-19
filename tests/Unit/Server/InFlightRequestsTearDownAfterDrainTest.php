<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Lifecycle\WorkerDrainSignal;
use Semitexa\Core\Server\InFlightRequests;

/**
 * A stream that ends because its worker is leaving gets its teardown spared by
 * the drain, like a request — and only then: a stream ending for any other
 * reason must not start counting as one.
 */
#[RequiresPhpExtension('swoole')]
final class InFlightRequestsTearDownAfterDrainTest extends TestCase
{
    protected function tearDown(): void
    {
        WorkerDrainSignal::reset();
        InFlightRequests::reset();
    }

    #[Test]
    public function a_stream_ending_for_the_drain_is_spared_like_a_request(): void
    {
        $spared = null;
        \Swoole\Coroutine\run(static function () use (&$spared): void {
            InFlightRequests::enter((int) \Swoole\Coroutine::getCid());
            InFlightRequests::streaming();
            WorkerDrainSignal::begin();

            InFlightRequests::tearDownAfterDrain();

            $spared = InFlightRequests::servesRequest((int) \Swoole\Coroutine::getCid());
        });

        self::assertTrue($spared);
    }

    #[Test]
    public function without_a_drain_it_changes_nothing(): void
    {
        $spared = null;
        \Swoole\Coroutine\run(static function () use (&$spared): void {
            InFlightRequests::enter((int) \Swoole\Coroutine::getCid());
            InFlightRequests::streaming();

            InFlightRequests::tearDownAfterDrain();

            $spared = InFlightRequests::servesRequest((int) \Swoole\Coroutine::getCid());
        });

        self::assertFalse($spared, 'a stream must stay a stream unless the worker is leaving');
    }
}
