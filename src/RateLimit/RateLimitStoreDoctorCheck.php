<?php

declare(strict_types=1);

namespace Semitexa\Core\RateLimit;

use Semitexa\Core\Attribute\AsDoctorCheck;
use Semitexa\Core\Contract\DoctorCheckInterface;
use Semitexa\Core\Environment;
use Semitexa\Core\Support\DoctorResult;

/** Whether #[RateLimit] counts will hold for the whole server, as configured. */
#[AsDoctorCheck(name: 'rate-limit.store', package: 'semitexa/core')]
final class RateLimitStoreDoctorCheck implements DoctorCheckInterface
{
    public function run(): DoctorResult
    {
        return self::verdict(
            trim((string) Environment::getEnvValue('RATE_LIMIT_STORE', 'auto')),
            trim((string) Environment::getEnvValue('REDIS_HOST', '')),
            trim((string) Environment::getEnvValue('SWOOLE_WORKER_NUM', '4')),
        );
    }

    public static function verdict(string $store, string $redisHost, string $workerNum): DoctorResult
    {
        if ($store !== '' && !RateLimitStoreChoice::isValidSetting($store)) {
            return DoctorResult::warn(
                'RATE_LIMIT_STORE=' . $store . ' is not a store; the server falls back to auto.',
                'Set RATE_LIMIT_STORE to auto, redis, table or memory.',
            );
        }

        $choice = RateLimitStoreChoice::tryFrom(strtolower($store)) ?? RateLimitStoreChoice::Auto;
        $workers = ctype_digit($workerNum) ? (int) $workerNum : 1;

        return match (true) {
            $choice === RateLimitStoreChoice::Redis && $redisHost === '' => DoctorResult::warn(
                'RATE_LIMIT_STORE=redis but REDIS_HOST is empty: limits are counted per worker.',
                'Set REDIS_HOST, or RATE_LIMIT_STORE=auto to share a table between this server\'s workers.',
            ),
            $choice === RateLimitStoreChoice::Memory && $workers > 1 => DoctorResult::warn(
                'RATE_LIMIT_STORE=memory with ' . $workers . ' workers: each counts alone, so a limit admits ' . $workers . ' times its number.',
                'Use RATE_LIMIT_STORE=auto (a table the workers share, or Redis when REDIS_HOST is set).',
            ),
            $redisHost !== '' && $choice->wantsRedis() => DoctorResult::pass('Rate limits count in Redis: they hold across servers.'),
            $choice->wantsTable() => DoctorResult::pass(
                'Rate limits count in a table this server\'s workers share (RATE_LIMIT_TABLE_SIZE rows); each server counts apart.',
            ),
            default => DoctorResult::pass('Rate limits count in the one worker this server runs.'),
        };
    }
}
