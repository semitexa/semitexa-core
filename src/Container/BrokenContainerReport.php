<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

use Semitexa\Core\Container\Exception\InjectionException;

/**
 * Every command boots the container, so one bad injection stops them all,
 * including the lint and the verification that exist to catch it. They answer
 * with what is wrong instead of a stack trace, and with a verdict on --json,
 * so a change can be refused before a restart takes the server down.
 */
final readonly class BrokenContainerReport
{
    private const EXIT_FAILURE = 1;

    public function __construct(private InjectionException $failure)
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
            "The container did not build, so no command can run, and a server restarted now would not start.\n\n%s\n",
            $this->failure->getMessage(),
        ));
        if (in_array('--json', $argv, true)) {
            fwrite($stdout, json_encode([
                'verdict' => 'fail',
                'error' => 'container_not_built',
                'class' => $this->failure->targetClass,
                'property' => $this->failure->propertyName,
                'type' => $this->failure->propertyType,
                'kind' => $this->failure->injectionKind,
                'message' => $this->failure->getMessage(),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        }

        return self::EXIT_FAILURE;
    }
}
