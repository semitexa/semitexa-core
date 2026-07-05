<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\GraphBuilder;
use Semitexa\Core\Container\NotFoundException;
use Semitexa\Core\Container\SemitexaContainer;
use Semitexa\Core\Exception\ContainerException;

/**
 * The container's autowiring errors sit on the most-travelled developer path
 * (wiring a service), so their messages must be ACTIONABLE — name the class, the
 * offending parameter and its type, state the rule (only class-typed params are
 * autowired), and give the concrete fix — not just "cannot resolve".
 */
final class ContainerResolutionMessageTest extends TestCase
{
    #[Test]
    public function a_scalar_constructor_param_error_names_the_rule_and_the_fix(): void
    {
        try {
            $this->build(NeedsAScalar::class);
            self::fail('a scalar constructor param must not autowire');
        } catch (ContainerException $e) {
            $msg = $e->getMessage();
            self::assertStringContainsString(NeedsAScalar::class, $msg, 'names the class');
            self::assertStringContainsString('$dsn', $msg, 'names the parameter');
            self::assertStringContainsString('"string"', $msg, 'names the type');
            self::assertStringContainsString('is not a service', $msg, 'states the rule');
            self::assertStringContainsString('default value', $msg, 'gives a fix');
            self::assertStringContainsString('#[InjectAsReadonly]', $msg, 'gives the property-injection fix');
        }
    }

    #[Test]
    public function an_unregistered_dependency_error_names_the_dep_and_the_fix(): void
    {
        try {
            $this->build(NeedsAnUnregisteredService::class);
            self::fail('an unregistered class dependency must not resolve');
        } catch (ContainerException $e) {
            $msg = $e->getMessage();
            self::assertStringContainsString(NeedsAnUnregisteredService::class, $msg, 'names the class');
            self::assertStringContainsString(UnregisteredService::class, $msg, 'names the missing dependency');
            self::assertStringContainsString('not a registered service', $msg, 'states the rule');
            self::assertStringContainsString('#[AsService]', $msg, 'gives the fix');
        }
    }

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

    private function build(string $class): object
    {
        $builder = (new \ReflectionClass(GraphBuilder::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(GraphBuilder::class, 'createInstanceWithConstructor');
        $method->setAccessible(true);

        return $method->invoke($builder, $class, [], [], [], []);
    }
}

final class NeedsAScalar
{
    public function __construct(public string $dsn)
    {
    }
}

final class UnregisteredService
{
}

final class NeedsAnUnregisteredService
{
    public function __construct(public UnregisteredService $svc)
    {
    }
}
