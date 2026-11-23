<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue\Fixtures;

/**
 * One public setter, and three set*() methods no caller outside the class can
 * reach: a payload's keys must not reach them either.
 */
final class SetterVisibilityDto
{
    public static ?string $shared = null;

    /** @var list<string> what was set, in order */
    public array $calls = [];

    public function setName(string $name): void
    {
        $this->calls[] = 'name=' . $name;
    }

    public static function setShared(string $shared): void
    {
        self::$shared = $shared;
    }

    protected function setInner(string $inner): void
    {
        $this->calls[] = 'inner=' . $inner;
    }

    private function setSecret(string $secret): void
    {
        $this->calls[] = 'secret=' . $secret;
    }
}
