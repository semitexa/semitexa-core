<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\RateLimit\Fixture;

/** Stands for a module's #[AsPayloadPart] trait. */
trait LimitedPayloadPart
{
    public ?string $note = null;
}
