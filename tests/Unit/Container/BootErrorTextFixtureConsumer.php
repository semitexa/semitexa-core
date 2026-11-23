<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container;

/** A worker-scoped class that takes the execution-scoped store as a property. */
final class BootErrorTextFixtureConsumer
{
    protected BootErrorTextFixtureStoreInterface $draftStore;
}
