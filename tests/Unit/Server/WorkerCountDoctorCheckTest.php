<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Server\WorkerCountDoctorCheck;

/**
 * What system:doctor says about SWOOLE_WORKER_NUM (tk-single-worker-recycle-warning).
 *
 * One worker leaves nothing serving while it is recycled. With
 * SWOOLE_MAX_REQUEST=0 as well Swoole runs it without a manager, and a recycle
 * ends the server (probed on 6.2.3: with 10000 the manager started a new
 * worker, with 0 the port refused). Warned in production, not refused.
 */
final class WorkerCountDoctorCheckTest extends TestCase
{
    private const HINT = 'Set SWOOLE_WORKER_NUM to 2 or more in .env, so a recycled worker is replaced while another serves.';

    #[Test]
    public function one_worker_in_production_warns_that_nothing_serves_while_it_is_recycled(): void
    {
        $result = WorkerCountDoctorCheck::verdict('1', '10000', 'prod');

        self::assertSame('warn', $result->status->value);
        self::assertSame(
            'SWOOLE_WORKER_NUM=1 in APP_ENV=prod: while a recycled worker (SWOOLE_MAX_REQUEST, the memory watchdog, '
            . 'a fatal error) is replaced, nothing serves and every open connection, SSE streams included, is cut.',
            $result->message,
        );
        self::assertSame(self::HINT, $result->hint);
    }

    #[Test]
    public function one_worker_without_a_request_limit_warns_that_a_recycle_ends_the_server(): void
    {
        foreach (['0', '-1'] as $maxRequest) {
            $result = WorkerCountDoctorCheck::verdict('1', $maxRequest, 'prod');

            self::assertSame('warn', $result->status->value);
            self::assertSame(
                'SWOOLE_WORKER_NUM=1 in APP_ENV=prod: with SWOOLE_MAX_REQUEST=' . $maxRequest . ' as well, Swoole runs '
                . 'that worker without a manager, so a memory-watchdog recycle or a fatal error ends the whole server: '
                . 'requests are refused and open connections are cut until the container is started again.',
                $result->message,
            );
            self::assertSame(self::HINT, $result->hint);
        }
    }

    #[Test]
    public function one_worker_in_development_passes_and_still_says_what_it_would_mean_in_production(): void
    {
        $result = WorkerCountDoctorCheck::verdict('1', '10000', 'dev');

        self::assertSame('pass', $result->status->value);
        self::assertSame(
            'One worker (APP_ENV=dev); in production, while a recycled worker (SWOOLE_MAX_REQUEST, the memory '
            . 'watchdog, a fatal error) is replaced, nothing serves and every open connection, SSE streams included, is cut.',
            $result->message,
        );
    }

    #[Test]
    public function two_workers_pass_whatever_the_request_limit(): void
    {
        foreach (['10000', '0'] as $maxRequest) {
            $result = WorkerCountDoctorCheck::verdict('2', $maxRequest, 'prod');

            self::assertSame('pass', $result->status->value);
            self::assertSame('2 workers: a recycled worker is replaced while the others serve.', $result->message);
        }
    }

    #[Test]
    public function a_value_that_is_not_a_worker_count_warns(): void
    {
        foreach (['0', 'four', '-1', ''] as $value) {
            $result = WorkerCountDoctorCheck::verdict($value, '10000', 'prod');

            self::assertSame('warn', $result->status->value, "'{$value}'");
            self::assertSame('SWOOLE_WORKER_NUM=' . $value . ' is not a number of workers.', $result->message);
        }
    }
}
