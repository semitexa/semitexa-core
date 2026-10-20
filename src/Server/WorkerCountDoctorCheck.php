<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

use Semitexa\Core\Attribute\AsDoctorCheck;
use Semitexa\Core\Contract\DoctorCheckInterface;
use Semitexa\Core\Environment;
use Semitexa\Core\Support\DoctorResult;

/**
 * How many workers the server runs, and what one means in production.
 *
 * With one worker every recycle (SWOOLE_MAX_REQUEST, the memory watchdog, a
 * fatal error) leaves nothing serving until the replacement has booted, and
 * cuts every open connection. Worse when SWOOLE_MAX_REQUEST is 0 as well:
 * Swoole then runs that worker with no manager at all (is_single_worker(),
 * probed on 6.2.3), so a recycle ends the whole server process and requests
 * are refused until Docker starts the container again. Warned in production,
 * only said in development; nothing is refused.
 */
#[AsDoctorCheck(name: 'server.worker-count', package: 'semitexa/core')]
final class WorkerCountDoctorCheck implements DoctorCheckInterface
{
    public function run(): DoctorResult
    {
        return self::verdict(
            trim((string) Environment::getEnvValue('SWOOLE_WORKER_NUM', '4')),
            trim((string) Environment::getEnvValue('SWOOLE_MAX_REQUEST', '10000')),
            trim((string) Environment::getEnvValue('APP_ENV', 'prod')),
        );
    }

    public static function verdict(string $workerNum, string $maxRequest, string $appEnv): DoctorResult
    {
        if (preg_match('/^[0-9]+$/', $workerNum) !== 1 || (int) $workerNum < 1) {
            return DoctorResult::warn(
                'SWOOLE_WORKER_NUM=' . $workerNum . ' is not a number of workers.',
                'Set SWOOLE_WORKER_NUM to 2 or more in .env (the scaffold uses 4).',
            );
        }

        $workers = (int) $workerNum;
        if ($workers >= 2) {
            return DoctorResult::pass($workers . ' workers: a recycled worker is replaced while the others serve.');
        }

        // As Environment reads it, and as Swoole takes it: anything but a positive count is none.
        $consequence = (int) $maxRequest > 0
            ? 'while a recycled worker (SWOOLE_MAX_REQUEST, the memory watchdog, a fatal error) is replaced, '
                . 'nothing serves and every open connection, SSE streams included, is cut.'
            : 'with SWOOLE_MAX_REQUEST=' . $maxRequest . ' as well, Swoole runs that worker without a manager, so '
                . 'a memory-watchdog recycle or a fatal error ends the whole server: requests are refused and open '
                . 'connections are cut until the container is started again.';
        if ($appEnv !== 'prod') {
            return DoctorResult::pass('One worker (APP_ENV=' . $appEnv . '); in production, ' . $consequence);
        }

        return DoctorResult::warn(
            'SWOOLE_WORKER_NUM=1 in APP_ENV=prod: ' . $consequence,
            'Set SWOOLE_WORKER_NUM to 2 or more in .env, so a recycled worker is replaced while another serves.',
        );
    }
}
