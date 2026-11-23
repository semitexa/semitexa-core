<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container\Fixtures\RegistryDecides;

/** A resolver whose getContract() someone edited to pick another implementation. */
final class EditedResolver
{
    public function __construct(private readonly object $choice)
    {
    }

    public function getContract(): object
    {
        return $this->choice;
    }
}
