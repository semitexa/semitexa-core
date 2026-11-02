<?php

declare(strict_types=1);

namespace Semitexa\Core\Application\Service\RouteDebug;

/** One handler of a route, as routes:show lists it. */
final readonly class RouteHandlerInfo
{
    public function __construct(
        public string $class,
        public string $module,
        public string $execution,
        public int $priority,
        public ?string $transport,
        public ?string $queue,
        public ?int $maxRetries,
        public ?int $retryDelay,
    ) {}

    /**
     * @return array{class: string, module: string, execution: string, priority: int, transport: ?string, queue: ?string, maxRetries: ?int, retryDelay: ?int}
     */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'module' => $this->module,
            'execution' => $this->execution,
            'priority' => $this->priority,
            'transport' => $this->transport,
            'queue' => $this->queue,
            'maxRetries' => $this->maxRetries,
            'retryDelay' => $this->retryDelay,
        ];
    }
}
