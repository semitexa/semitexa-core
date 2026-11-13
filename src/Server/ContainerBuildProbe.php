<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

/**
 * Builds the container once in a separate PHP process, so the master can refuse to start a server
 * whose workers could never boot (tk-master-loads-no-app-classes).
 *
 * The master used to build the container itself, and a container that could not build (an
 * ambiguous contract, a bad environment) threw there: server:start failed. Since the master loads
 * no application class, every worker builds its own, and the same fault would throw in every
 * WorkerStart and respawn forever (Fable's review, 13.11). The build runs in a child process
 * so the master still loads nothing of it.
 */
final class ContainerBuildProbe
{
    /** Null when the container builds; otherwise what the failed build printed. */
    public static function failure(string $projectRoot): ?string
    {
        $script = sprintf(
            'require %s; \Semitexa\Core\Container\ContainerFactory::create();',
            var_export($projectRoot . '/vendor/autoload.php', true),
        );
        // Errors to stdout, and stderr into a file read after the build: one pipe, so no deadlock,
        // and what a build writes to STDERR is kept (review, core#232).
        $stderr = tempnam(sys_get_temp_dir(), 'semitexa-build-probe-');
        if ($stderr === false) {
            return 'could not create a file for the build output';
        }
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stdout', '-d', 'log_errors=0', '-r', $script],
            [1 => ['pipe', 'w'], 2 => ['file', $stderr, 'w']],
            $pipes,
            $projectRoot,
        );
        if (!is_resource($process)) {
            @unlink($stderr);

            return 'could not start a PHP process to build the container';
        }
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($process);
        $output .= (string) @file_get_contents($stderr);
        @unlink($stderr);
        if ($code === 0) {
            return null;
        }

        return trim($output) !== '' ? trim($output) : "the build exited with code {$code}";
    }
}
