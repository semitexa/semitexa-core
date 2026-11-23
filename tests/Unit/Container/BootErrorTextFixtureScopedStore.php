<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container;

/** Registered as execution-scoped by the test, so it cannot be a boot-time property. */
final class BootErrorTextFixtureScopedStore implements BootErrorTextFixtureStoreInterface
{
}
