<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Integration;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which value a project gets when it is set in more than one place — the
 * order every install relies on and nothing proved (tk-parity-proof-gaps):
 * the committed .env.default, the machine's .env over it, and the process
 * environment (Docker, compose) over both.
 *
 * In a child process: the project root and the parsed env files are
 * process-wide, and this one is a throwaway project.
 */
final class EnvFilePrecedenceTest extends TestCase
{
    private const CHILD = <<<'PHP'
        <?php
        [, $autoload, $root] = $argv;
        define('SEMITEXA_PROJECT_ROOT', $root);
        require $autoload;
        $out = [];
        foreach (['ONLY_DEFAULT', 'OVERRIDDEN_LOCALLY', 'SET_EVERYWHERE', 'NOWHERE'] as $key) {
            $out[$key] = Semitexa\Core\Environment::getEnvValue('SEMITEXA_PRECEDENCE_' . $key, 'fallback');
        }
        echo json_encode($out);
        PHP;

    #[Test]
    public function the_process_env_wins_over_dot_env_which_wins_over_dot_env_default(): void
    {
        $root = sys_get_temp_dir() . '/semitexa-env-precedence-' . bin2hex(random_bytes(4));
        mkdir($root, 0o755, true);
        file_put_contents($root . '/.env.default', implode("\n", [
            'SEMITEXA_PRECEDENCE_ONLY_DEFAULT=from-default',
            'SEMITEXA_PRECEDENCE_OVERRIDDEN_LOCALLY=from-default',
            'SEMITEXA_PRECEDENCE_SET_EVERYWHERE=from-default',
        ]) . "\n");
        file_put_contents($root . '/.env', implode("\n", [
            'SEMITEXA_PRECEDENCE_OVERRIDDEN_LOCALLY=from-dot-env',
            'SEMITEXA_PRECEDENCE_SET_EVERYWHERE=from-dot-env',
        ]) . "\n");
        file_put_contents($root . '/child.php', self::CHILD);
        $autoload = dirname((string) (new \ReflectionClass(ClassLoader::class))->getFileName(), 2) . '/autoload.php';

        try {
            $env = getenv();
            foreach (array_keys($env) as $key) {
                if (str_starts_with($key, 'SEMITEXA_PRECEDENCE_')) {
                    unset($env[$key]);
                }
            }
            $env['SEMITEXA_PRECEDENCE_SET_EVERYWHERE'] = 'from-process';
            $process = proc_open([PHP_BINARY, $root . '/child.php', $autoload, $root], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
            self::assertIsResource($process);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), 'the child failed: ' . $err);

            self::assertSame([
                'ONLY_DEFAULT' => 'from-default',
                'OVERRIDDEN_LOCALLY' => 'from-dot-env',
                'SET_EVERYWHERE' => 'from-process',
                'NOWHERE' => 'fallback',
            ], json_decode((string) $out, true));
        } finally {
            foreach (['.env.default', '.env', 'child.php'] as $file) {
                @unlink($root . '/' . $file);
            }
            @rmdir($root);
        }
    }
}
