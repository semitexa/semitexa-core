<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http\Fixtures;

final class HydratorTextFixturePayload
{
    public ?string $name = null;

    /** @var array<array-key, mixed> */
    public array $tags = [];

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    /** @param array<array-key, mixed> $tags */
    public function setTags(array $tags): void
    {
        $this->tags = $tags;
    }
}
