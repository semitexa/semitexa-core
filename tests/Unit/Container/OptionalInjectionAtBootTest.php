<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Semitexa\Core\Container\BuildPhase\BuildContext;
use Semitexa\Core\Container\BuildPhase\ValidationPhase;
use Semitexa\Core\Container\Exception\InjectionException;
use Semitexa\Core\Container\GraphBuilder;
use Semitexa\Core\Container\Store\InjectionMap;
use Semitexa\Core\Container\Store\InstanceStore;
use Semitexa\Core\Container\Store\TypeMap;

interface BootOptionalMissingInterface
{
}

final class BootOptionalConsumer
{
    protected BootOptionalMissingInterface $missing;
}

/**
 * `#[InjectAsReadonly(optional: true)]` promises that an unbound dependency is
 * skipped and the property stays uninitialized. The boot path (graph build and
 * boot validation) ignored the flag and failed the whole container instead.
 */
final class OptionalInjectionAtBootTest extends TestCase
{
    #[Test]
    public function optional_unbound_readonly_dependency_is_skipped_when_building_the_graph(): void
    {
        $readonly = [];
        (new GraphBuilder())->buildReadonlyGraph(
            [BootOptionalConsumer::class => BootOptionalConsumer::class],
            [],
            $this->injections(optional: true),
            $readonly,
            static fn (string $id): ?string => class_exists($id) ? $id : null,
        );

        self::assertArrayHasKey(BootOptionalConsumer::class, $readonly);
        self::assertFalse(
            (new ReflectionProperty(BootOptionalConsumer::class, 'missing'))->isInitialized($readonly[BootOptionalConsumer::class]),
        );
    }

    #[Test]
    public function required_unbound_readonly_dependency_still_fails_the_graph_build(): void
    {
        $readonly = [];

        $this->expectException(InjectionException::class);
        (new GraphBuilder())->buildReadonlyGraph(
            [BootOptionalConsumer::class => BootOptionalConsumer::class],
            [],
            $this->injections(optional: false),
            $readonly,
            static fn (string $id): ?string => class_exists($id) ? $id : null,
        );
    }

    #[Test]
    public function boot_validation_accepts_an_optional_unbound_dependency(): void
    {
        (new ValidationPhase())->execute($this->context(optional: true));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function boot_validation_still_rejects_a_required_unbound_dependency(): void
    {
        $this->expectException(InjectionException::class);
        (new ValidationPhase())->execute($this->context(optional: false));
    }

    /**
     * @return array<class-string, array<string, array{kind: string, type: class-string, optional: bool}>>
     */
    private function injections(bool $optional): array
    {
        return [
            BootOptionalConsumer::class => [
                'missing' => ['kind' => 'readonly', 'type' => BootOptionalMissingInterface::class, 'optional' => $optional],
            ],
        ];
    }

    private function context(bool $optional): BuildContext
    {
        $context = new BuildContext(new InstanceStore(), new TypeMap(), new InjectionMap());
        $context->injections = $this->injections($optional);

        return $context;
    }
}
