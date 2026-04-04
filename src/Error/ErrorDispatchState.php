<?php

declare(strict_types=1);

namespace Semitexa\Core\Error;

final class ErrorDispatchState
{
    /** @var array<string, true> */
    private array $activeRoutes = [];

    public function enter(string $routeName): bool
    {
        if (isset($this->activeRoutes[$routeName])) {
            return false;
        }

        $this->activeRoutes[$routeName] = true;

        return true;
    }

    public function leave(string $routeName): void
    {
        unset($this->activeRoutes[$routeName]);
    }
}
