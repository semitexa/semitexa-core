<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

use Semitexa\Core\Container\Exception\InjectionException;

/**
 * Commands boot the container, so one bad injection stops all but the server
 * lifecycle ones, including the lint and the verification that exist to catch it. They answer
 * with what is wrong instead of a stack trace, and with a verdict on --json,
 * so a change can be refused before a restart takes the server down.
 */
final readonly class BrokenContainerReport
{
    private const EXIT_FAILURE = 1;

    public function __construct(private \Throwable $failure)
    {
    }

    /**
     * @param list<string> $argv
     * @param resource     $stdout
     * @param resource     $stderr
     */
    public function write(array $argv, $stdout, $stderr): int
    {
        fwrite($stderr, sprintf(
            "The container did not build, so commands that need it cannot run, and a server restarted now would not start.\n\n%s\n",
            $this->failure->getMessage(),
        ));
        if (in_array('--json', $argv, true)) {
            $where = !$this->failure instanceof InjectionException ? [] : [
                'class' => $this->failure->targetClass,
                'property' => $this->failure->propertyName,
                'type' => $this->failure->propertyType,
                'kind' => $this->failure->injectionKind,
            ];
            // Substituted, not thrown: a stray byte must not turn this report back into a fatal.
            fwrite($stdout, json_encode(
                ['verdict' => 'fail', 'error' => 'container_not_built'] + $where + ['message' => $this->failure->getMessage()],
                JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
            ) . "\n");
        }

        return self::EXIT_FAILURE;
    }
}
