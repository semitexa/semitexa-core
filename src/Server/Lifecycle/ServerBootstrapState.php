<?php

declare(strict_types=1);

namespace Semitexa\Core\Server\Lifecycle;

final class ServerBootstrapState
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }
}
