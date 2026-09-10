<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\GraphBuilder;
use Semitexa\Core\Contract\InitializesAfterInjectionInterface;
use Semitexa\Core\Exception\ContainerException;

final class InitFixtureInitialized implements InitializesAfterInjectionInterface
{
    public bool $initialized = false;

    public bool $constructed = false;

    public function __construct()
    {
        // Deliberately here. One of the tests below proves it never runs — which
        // is the whole reason initialize() exists.
        $this->constructed = true;
    }

    public function initialize(): void
    {
        $this->initialized = true;
    }
}

final class InitFixtureFailing implements InitializesAfterInjectionInterface
{
    public function initialize(): void
    {
        throw new \RuntimeException('deliberate');
    }
}

final class InitFixturePlain
{
    public bool $touched = false;
}

/**
 * The container calls initialize(); it does not call constructors.
 *
 * Both halves matter. Without the first, a class told to stop putting work in
 * its constructor has nowhere to put it. Without the second, the rule that sent
 * it here would be arbitrary.
 *
 * Measured on 2026-09-10: four classes in this workspace had a constructor body
 * the container never ran, and one of them called itself fail-closed.
 */
final class InitializesAfterInjectionTest extends TestCase
{
    #[Test]
    public function initialize_runs_and_the_constructor_does_not(): void
    {
        $instance = $this->build(InitFixtureInitialized::class);

        self::assertInstanceOf(InitFixtureInitialized::class, $instance);
        self::assertTrue($instance->initialized, 'initialize() must run before the object is handed over');
        self::assertFalse($instance->constructed, 'the constructor body is skipped — that is why initialize() exists');
    }

    #[Test]
    public function a_class_without_the_interface_is_left_alone(): void
    {
        $instance = $this->build(InitFixturePlain::class);

        self::assertInstanceOf(InitFixturePlain::class, $instance);
        self::assertFalse($instance->touched);
    }

    #[Test]
    public function a_failing_initialize_names_the_class_instead_of_handing_back_a_half_built_object(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/InitFixtureFailing::initialize\(\) failed after injection: deliberate/');

        $this->build(InitFixtureFailing::class);
    }

    /**
     * Drive GraphBuilder's instantiation path directly: these fixtures live under
     * tests/ and discovery only scans src/, so a full container would never see
     * them.
     *
     * @param class-string $class
     */
    private function build(string $class): object
    {
        $builder = (new \ReflectionClass(GraphBuilder::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(GraphBuilder::class, 'createInstance');

        return (object) $method->invoke($builder, $class, [], [], [], []);
    }
}
