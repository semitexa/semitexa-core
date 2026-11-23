<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Core\Attribute\AsService;

/**
 * Fixture: what WorkerServiceCallerStateRule must flag.
 *
 * The proven case of 22.11.2026: a handler calls remember(), another request
 * calls it too, and both read back whichever tenant was written last.
 *
 * Lines are asserted by the test: append to this file, do not insert.
 */
#[AsService]
final class CallerStateSharedMemo
{
    protected string $tenant = '';

    /** @var array<string, string> */
    private array $notes = [];

    /** @var list<string> */
    private array $seen = [];

    private int $written = 0;

    public function remember(string $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function note(string $key, string $text): void
    {
        $this->notes[$key] = trim($text);
    }

    public function see(string $who): void
    {
        $this->seen[] = $who;
        $this->seen[] = $who;
    }

    public function count(int $bytes): void
    {
        $this->written += $bytes;
    }

    public function tenant(): string
    {
        return $this->tenant . count($this->notes) . count($this->seen) . $this->written;
    }
}
