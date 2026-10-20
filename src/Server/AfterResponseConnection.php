<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

/**
 * @internal What {@see AfterResponse} keeps per held-open connection: the work
 * of its ticks not yet run, and whether the one coroutine that runs it is up.
 */
final class AfterResponseConnection
{
    /** @var \SplQueue<\Closure(): void> */
    public \SplQueue $pending;

    public bool $draining = false;

    public function __construct()
    {
        $this->pending = new \SplQueue();
    }
}
