<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Tests\Fixtures\ImpliedServiceDemo\MarkedJob;
use Semitexa\Core\Tests\Fixtures\ImpliedServiceDemo\MarksNothing;
use Semitexa\Core\Tests\Fixtures\ImpliedServiceDemo\UnmarkedJob;
use Semitexa\Core\Tests\Support\FixtureModules;

/**
 * idea-scheduled-job-needs-no-as-service: a package says that a class carrying
 * one of its attributes is a container service by having the attribute class
 * implement ImpliesService, so core names no downstream package. The
 * scheduler's jobs carried #[AsService] only so the worker could build them by
 * class name.
 */
final class AnAttributeCanMakeItsCarriersServicesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        FixtureModules::install();
    }

    public static function tearDownAfterClass(): void
    {
        FixtureModules::uninstall();
    }

    #[Test]
    public function a_class_carrying_an_attribute_that_implies_a_service_is_built_with_its_injections(): void
    {
        $container = ContainerFactory::get();

        self::assertTrue($container->has(MarkedJob::class));
        $job = $container->get(MarkedJob::class);
        self::assertInstanceOf(MarkedJob::class, $job);
        self::assertSame(
            $container->get(AttributeDiscovery::class),
            $job->discovery(),
            'its #[InjectAsReadonly] property is injected, as an #[AsService] one is',
        );
    }

    #[Test]
    public function a_class_carrying_any_other_attribute_is_not_a_service(): void
    {
        self::assertContains(
            UnmarkedJob::class,
            (new ClassDiscovery())->findClassesWithAttribute(MarksNothing::class),
            'discovery sees it, so the next assertion is about registration, not about a class nobody found',
        );
        self::assertFalse(ContainerFactory::get()->has(UnmarkedJob::class));
    }
}
