<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Runtime;

use Semitexa\Core\Environment;
use Semitexa\Core\Support\ProjectRoot;
use Symfony\Component\Console\Style\SymfonyStyle;

final class VerifyRuntimeAction
{
    private const MAX_ATTEMPTS = 10;
    private const DELAY_SECONDS = 2;

    public function __construct(private readonly SymfonyStyle $io) {}

    /**
     * Verify the runtime is healthy after start/restart/reload.
     *
     * @param bool $checkBuildMarker Whether to verify build hash matches (true for start/restart, false for reload)
     */
    public function execute(bool $checkBuildMarker = false): bool
    {
        $this->io->text('<info>[verify]</info> Verifying runtime...');

        $port = (int) Environment::getEnvValue('SWOOLE_PORT', '9502');
        $expectedHash = null;

        if ($checkBuildMarker) {
            $markerFile = ProjectRoot::get() . '/var/runtime/build.hash';
            if (is_file($markerFile)) {
                $expectedHash = trim((string) file_get_contents($markerFile));
            }
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            if ($attempt > 1) {
                sleep(self::DELAY_SECONDS);
            }

            $result = $this->checkHealth($port);

            if ($result === null) {
                $this->io->text("<info>[verify]</info> Attempt {$attempt}/" . self::MAX_ATTEMPTS . ': waiting for health endpoint...');
                continue;
            }

            // Health endpoint responded
            if (($result['status'] ?? '') !== 'ok') {
                $this->io->text("<info>[verify]</info> Attempt {$attempt}/" . self::MAX_ATTEMPTS . ': health status not ok.');
                continue;
            }

            // Check build marker if required
            if ($expectedHash !== null) {
                $runtimeHash = isset($result['build_hash']) && is_string($result['build_hash']) ? $result['build_hash'] : null;
                if ($runtimeHash !== $expectedHash) {
                    $this->io->text("<info>[verify]</info> Attempt {$attempt}/" . self::MAX_ATTEMPTS . ': build hash mismatch (expected: ' . $expectedHash . ', got: ' . ($runtimeHash ?? 'null') . ')');
                    continue;
                }
            }

            $hashInfo = $expectedHash !== null ? " Build: {$expectedHash}" : '';
            $this->io->text("<info>[verify]</info> Runtime is healthy.{$hashInfo}");
            return true;
        }

        $this->io->error('Runtime verification failed after ' . self::MAX_ATTEMPTS . ' attempts.');
        $this->io->text([
            'The runtime did not respond correctly on port ' . $port . '.',
            'Check logs: docker compose logs -f',
            'Or Swoole log: var/log/swoole.log',
        ]);
        return false;
    }

    /**
     * @return array<string, mixed>|null  null if unreachable
     */
    private function checkHealth(int $port): ?array
    {
        $url = "http://127.0.0.1:{$port}/health";

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 3,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return null;
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }

        return $data;
    }
}
