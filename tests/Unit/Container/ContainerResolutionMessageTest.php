<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\NotFoundException;
use Semitexa\Core\Container\SemitexaContainer;

/**
 * The container's autowiring errors sit on the most-travelled developer path
 * (wiring a service), so their messages must be ACTIONABLE — name the class, the
 * offending parameter and its type, state the rule (only class-typed params are
 * autowired), and give the concrete fix — not just "cannot resolve".
 */
final class ContainerResolutionMessageTest extends TestCase
{
    // verify:accept-test-change the two constructor-autowiring message tests built through GraphBuilder::createInstanceWithConstructor(), which only built generated registry resolvers and left with them (d-20261123-di-registry-one-source)
    #[Test]
    public function an_unknown_service_error_states_how_to_register_it(): void
    {
        try {
            (new SemitexaContainer())->get('App\\Definitely\\NotAThing');
            self::fail('an unknown service id must throw');
        } catch (NotFoundException $e) {
            $msg = $e->getMessage();
            self::assertStringContainsString('App\\Definitely\\NotAThing', $msg, 'names the id');
            self::assertStringContainsString('never discovered', $msg, 'states the cause');
            self::assertStringContainsString('#[AsService]', $msg, 'gives the fix');
        }
    }
}
