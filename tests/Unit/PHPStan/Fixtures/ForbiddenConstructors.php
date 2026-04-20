<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Core\Attribute\AsEventListener;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\AsService;

/**
 * Fixture: constructor usages that InjectionViaConstructorRule MUST flag.
 *
 * Each class below uses __construct(...) parameters as a DI channel on a
 * container-managed class. The rule must reject all of them.
 */

/**
 * FORBIDDEN — constructor injection on #[AsService].
 */
#[AsService]
final class ServiceWithConstructorInjection
{
    public function __construct(
        private readonly \Psr\Log\LoggerInterface $logger,
    ) {
    }
}

/**
 * FORBIDDEN — constructor injection on #[AsPayloadHandler].
 */
#[AsPayloadHandler(payload: \stdClass::class, resource: \stdClass::class)]
final class HandlerWithConstructorInjection
{
    public function __construct(
        private readonly \Psr\Log\LoggerInterface $logger,
    ) {
    }
}

/**
 * FORBIDDEN — constructor injection on #[AsEventListener].
 */
#[AsEventListener(event: \stdClass::class)]
final class ListenerWithConstructorInjection
{
    public function __construct(
        private readonly \Psr\Log\LoggerInterface $logger,
    ) {
    }
}
