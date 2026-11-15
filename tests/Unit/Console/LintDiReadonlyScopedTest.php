<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Application\Console\Command\LintDiCommand;
use Semitexa\Core\Container\ExecutionContextAwareContainerInterface;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Core\Tests\Unit\PHPStan\Fixtures\ReadonlyInjectionHolder;
use Semitexa\Core\Tests\Unit\PHPStan\Fixtures\ScopedFixtureService;

/**
 * lint:di refuses #[InjectAsReadonly] on an execution-scoped type, asking the
 * container PropertyInjector asks at runtime (tk-inject-from-context-attr), so
 * an interface counts by the class it is bound to.
 */
final class LintDiReadonlyScopedTest extends TestCase
{
    #[Test]
    public function the_container_decides_and_an_interface_counts_by_its_binding(): void
    {
        $said = $this->judge([ScopedFixtureService::class, EventDispatcherInterface::class]);

        self::assertSame([
            'scoped' => 'Cannot inject execution-scoped ' . ScopedFixtureService::class . ' into ' . ReadonlyInjectionHolder::class . '::$scoped with #[InjectAsReadonly]. Use #[InjectAsMutable] on container-managed objects instead.',
            'byInterface' => 'Cannot inject execution-scoped ' . EventDispatcherInterface::class . ' into ' . ReadonlyInjectionHolder::class . '::$byInterface with #[InjectAsReadonly]. Use #[InjectAsMutable] on container-managed objects instead.',
            'nullable' => 'Cannot inject execution-scoped ' . ScopedFixtureService::class . ' into ' . ReadonlyInjectionHolder::class . '::$nullable with #[InjectAsReadonly]. Use #[InjectAsMutable] on container-managed objects instead.',
        ], $said, '#[InjectAsMutable] $mutable is not judged, a worker-scoped type is not refused');
    }

    #[Test]
    public function nothing_is_refused_when_the_container_scopes_nothing(): void
    {
        self::assertCount(7, (new \ReflectionClass(ReadonlyInjectionHolder::class))->getProperties(), 'the fixture still has its seven properties');
        self::assertSame([], $this->judge([]));
    }

    /**
     * @param list<string> $scoped what the stub container calls execution-scoped
     * @return array<string, string> property => what lint:di says
     */
    private function judge(array $scoped): array
    {
        $container = new class ($scoped) implements ContainerInterface, ExecutionContextAwareContainerInterface {
            /** @param list<string> $scoped */
            public function __construct(private readonly array $scoped) {}

            public function get(string $id): mixed
            {
                throw new \LogicException('not used');
            }

            public function has(string $id): bool
            {
                return true;
            }

            public function setExecutionContext(\Semitexa\Core\Container\ExecutionContext $context): void {}

            public function isExecutionScoped(string $id): bool
            {
                return in_array($id, $this->scoped, true);
            }
        };

        $command = (new \ReflectionClass(LintDiCommand::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(LintDiCommand::class, 'container'))->setValue($command, $container);
        $judge = new \ReflectionMethod(LintDiCommand::class, 'readonlyInjectionOfExecutionScoped');

        $said = [];
        foreach ((new \ReflectionClass(ReadonlyInjectionHolder::class))->getProperties() as $prop) {
            $line = $judge->invoke($command, ReadonlyInjectionHolder::class, $prop);
            if ($line !== null) {
                $said[$prop->getName()] = $line;
            }
        }

        return $said;
    }
}
