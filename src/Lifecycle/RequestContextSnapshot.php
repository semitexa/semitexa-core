<?php

declare(strict_types=1);

namespace Semitexa\Core\Lifecycle;

use Semitexa\Core\Container\ExecutionContext;
use Semitexa\Core\Container\SemitexaContainer;

/**
 * A request's whole context, taken while the request runs, for work that runs
 * after the response or in a coroutine of its own: the container's execution
 * context (what it injects) and what the stores keep beside it
 * ({@see CarriedContext}: tenant, signed-in user, locale, theme...). Either
 * half alone left the work in the wrong tenant or locale, or refused it.
 *
 * {@see none()} is what a queue worker has: no request at all. Work that must
 * behave here as it would behind a queue runs with it, whatever context is
 * live where it happens to run.
 */
final class RequestContextSnapshot
{
    /** @param array<string, mixed> $carried */
    private function __construct(
        private readonly ?SemitexaContainer $container,
        private readonly ?ExecutionContext $execution,
        private readonly array $carried,
    ) {
    }

    /** The running request's context, now: the request clears it once the response is out. */
    public static function capture(?SemitexaContainer $container): self
    {
        return new self($container, $container?->captureExecutionContext(), CarriedContext::capture());
    }

    public static function none(?SemitexaContainer $container): self
    {
        return new self($container, $container !== null ? new ExecutionContext() : null, []);
    }

    /**
     * Runs $work in this context, then puts back what was there.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function run(\Closure $work): mixed
    {
        $container = $this->container;
        $execution = $this->execution;

        return CarriedContext::runWith($this->carried, static function () use ($container, $execution, $work): mixed {
            if ($container !== null && $execution !== null) {
                return $container->runWithExecutionContext($execution, $work);
            }

            return $work();
        });
    }
}
