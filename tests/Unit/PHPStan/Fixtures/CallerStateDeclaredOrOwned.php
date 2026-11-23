<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\Config;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\WorkerState;

/**
 * Fixture: what WorkerServiceCallerStateRule must leave alone on a
 * worker-shared service. Every property here is written from a parameter.
 */
#[AsService]
final class CallerStateDeclaredOrOwned
{
    /** ALLOWED: declared worker-wide, with the reason. */
    #[WorkerState('Registry keyed by name, filled at worker boot from discovery.')]
    private array $registry = [];

    /** ALLOWED: the container owns injected properties. */
    #[InjectAsReadonly]
    protected WorkerFixtureService $readonlyDependency;

    #[InjectAsMutable]
    protected ScopedFixtureService $mutableDependency;

    /** ALLOWED: the container owns configuration. */
    #[Config('fixture.limit')]
    protected int $limit = 10;

    /** ALLOWED: written only while the instance is being built. */
    private string $prefix = '';

    private string $suffix = '';

    /** ALLOWED: nothing a caller passed reaches it. */
    private int $calls = 0;

    private ?string $memo = null;

    public function __construct(string $prefix = '')
    {
        $this->prefix = $prefix;
    }

    public function initialize(string $suffix = ''): void
    {
        $this->suffix = $suffix;
    }

    public function register(string $name, object $entry): void
    {
        $this->registry[$name] = $entry;
    }

    public function swap(WorkerFixtureService $readonly, ScopedFixtureService $mutable, int $limit): void
    {
        $this->readonlyDependency = $readonly;
        $this->mutableDependency = $mutable;
        $this->limit = $limit;
    }

    public function call(string $key): string
    {
        $this->calls = $this->calls + 1;
        $this->memo ??= strtoupper('memo');
        $local = $key;
        $other = new \stdClass();
        $other->tenant = $key;

        return $this->prefix . $this->suffix . $local . $this->calls . $this->memo . count($this->registry);
    }
}
