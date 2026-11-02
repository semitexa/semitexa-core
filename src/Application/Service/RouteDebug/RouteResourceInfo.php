<?php

declare(strict_types=1);

namespace Semitexa\Core\Application\Service\RouteDebug;

/** The response resource a route renders, as routes:show reports it. */
final readonly class RouteResourceInfo
{
    public function __construct(
        public ClassLocation $location,
        public ?string $handle,
        public ?string $format,
        public ?string $renderer,
    ) {}

    /** @return array{class: string, module: string, file: ?string, handle: ?string, format: ?string, renderer: ?string} */
    public function toArray(): array
    {
        return $this->location->toArray() + ['handle' => $this->handle, 'format' => $this->format, 'renderer' => $this->renderer];
    }
}
