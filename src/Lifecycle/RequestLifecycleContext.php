<?php

declare(strict_types=1);

namespace Semitexa\Core\Lifecycle;

use Semitexa\Core\Request;
use Semitexa\Core\HttpResponse;

/**
 * @internal Carries mutable state between request lifecycle phases.
 */
final class RequestLifecycleContext
{
    private ?HttpResponse $earlyResponse = null;
    private ?string $localeStrippedPath = null;

    public function __construct(
        public readonly Request $request,
    ) {}

    public function setEarlyResponse(HttpResponse $response): void
    {
        $this->earlyResponse = $response;
    }

    public function hasEarlyResponse(): bool
    {
        return $this->earlyResponse !== null;
    }

    public function getEarlyResponse(): HttpResponse
    {
        if ($this->earlyResponse === null) {
            throw new \LogicException('No early response set');
        }
        return $this->earlyResponse;
    }

    public function setLocaleStrippedPath(?string $path): void
    {
        $this->localeStrippedPath = $path;
    }

    public function getRoutingPath(): string
    {
        if ($this->localeStrippedPath !== null) {
            return $this->localeStrippedPath;
        }
        return $this->request->getPath();
    }
}
