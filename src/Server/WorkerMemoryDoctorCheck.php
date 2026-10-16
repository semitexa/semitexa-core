<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

use Semitexa\Core\Attribute\AsDoctorCheck;
use Semitexa\Core\Contract\DoctorCheckInterface;
use Semitexa\Core\Environment;
use Semitexa\Core\Server\Lifecycle\WorkerMemoryWatchdogListener;
use Semitexa\Core\Support\DoctorResult;

/**
 * A worker's memory limit and where the watchdog recycles it.
 *
 * Reads PHP_MEMORY_LIMIT, which docker-compose.yml hands the server as
 * `-d memory_limit`, and never this process's own ini: doctor runs in the CLI,
 * and on semitexa.com (2026-10-16) the CLI said 128M while every worker ran
 * with 256M.
 *
 * A Swoole worker lives for thousands of requests and holds every open SSE
 * session, so the 128M a request-per-process SAPI gets is too little: the
 * portal workers of August 2026 died at it. Below the floor this warns.
 */
#[AsDoctorCheck(name: 'server.worker-memory', package: 'semitexa/core')]
final class WorkerMemoryDoctorCheck implements DoctorCheckInterface
{
    public const FLOOR = '256M';

    public function run(): DoctorResult
    {
        $configured = Environment::getEnvValue('PHP_MEMORY_LIMIT');

        return self::verdict(
            is_string($configured) && trim($configured) !== '' ? trim($configured) : null,
            WorkerMemoryWatchdogListener::ratio(),
        );
    }

    public static function verdict(?string $memoryLimit, float $ratio): DoctorResult
    {
        if ($memoryLimit === null) {
            return DoctorResult::warn(
                'PHP_MEMORY_LIMIT is not set, so the server runs with whatever its compose command defaults '
                . 'to (512M in the scaffold\'s docker-compose.yml) and this check cannot say what that is.',
                'Set PHP_MEMORY_LIMIT in .env.default or .env (the scaffold uses 512M).',
            );
        }

        $limit = WorkerMemoryWatchdog::parseBytes($memoryLimit);
        if ($limit === null) {
            return DoctorResult::warn(
                'PHP_MEMORY_LIMIT=' . $memoryLimit . ' sets no limit, so a leaking worker grows until the host '
                . 'runs out, and the memory watchdog has nothing to measure against.',
                'Give workers a limit (the scaffold uses 512M); the watchdog then recycles one at '
                . 'SWOOLE_MEMORY_WATCHDOG_RATIO of it.',
            );
        }

        $floor = (int) WorkerMemoryWatchdog::parseBytes(self::FLOOR);
        if ($limit < $floor) {
            return DoctorResult::warn(
                'PHP_MEMORY_LIMIT=' . $memoryLimit . ' is below what a long-lived Swoole worker needs.',
                'Raise it to at least ' . self::FLOOR . ' (the scaffold uses 512M): a worker holds every open '
                . 'SSE session for its whole life, and at 128M workers die mid-request under load.',
            );
        }

        $watchdog = WorkerMemoryWatchdog::fromLimit($memoryLimit, $ratio);
        if ($watchdog === null) {
            return DoctorResult::warn(
                'Workers run with memory_limit ' . $memoryLimit . ' and the memory watchdog is off '
                . '(SWOOLE_MEMORY_WATCHDOG_RATIO=' . $ratio . '): a worker that stops finishing requests is '
                . 'never recycled by SWOOLE_MAX_REQUEST and dies at the limit, mid-request.',
                'Unset SWOOLE_MEMORY_WATCHDOG_RATIO for the default (0.8), or set a value between 0 and 1.',
            );
        }

        return DoctorResult::pass(sprintf(
            'Workers run with memory_limit %s; the watchdog recycles one above %.1f MB (ratio %s).',
            $memoryLimit,
            $watchdog->thresholdBytes / 1048576,
            rtrim(rtrim(number_format($ratio, 3, '.', ''), '0'), '.'),
        ));
    }
}
