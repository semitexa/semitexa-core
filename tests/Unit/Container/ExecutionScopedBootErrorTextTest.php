<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\BuildPhase\BuildContext;
use Semitexa\Core\Container\BuildPhase\ValidationPhase;
use Semitexa\Core\Container\Exception\InjectionException;
use Semitexa\Core\Container\GraphBuilder;
use Semitexa\Core\Container\Store\InjectionMap;
use Semitexa\Core\Container\Store\InstanceStore;
use Semitexa\Core\Container\Store\TypeMap;

/**
 * The boot error for #[InjectAsReadonly] on an #[ExecutionScoped] type is read
 * by an application developer. It named two framework repositories and an
 * internal incident, none of which the reader can open or act on. It must name
 * the reader's own class and property, say why, and give the two fixes.
 */
final class ExecutionScopedBootErrorTextTest extends TestCase
{
    #[Test]
    public function the_graph_build_error_speaks_to_the_application_developer(): void
    {
        $readonly = [];
        try {
            (new GraphBuilder())->buildReadonlyGraph(
                [BootErrorTextFixtureConsumer::class => BootErrorTextFixtureConsumer::class],
                [BootErrorTextFixtureScopedStore::class => true],
                $this->injections(),
                $readonly,
                static fn (string $id): ?string => class_exists($id) ? $id : null,
            );
            self::fail('a worker-scoped class took an execution-scoped service and the graph was built');
        } catch (InjectionException $e) {
            $this->assertSpeaksToTheApplicationDeveloper($e->getMessage());
        }
    }

    #[Test]
    public function the_boot_validation_error_speaks_to_the_application_developer(): void
    {
        $context = new BuildContext(new InstanceStore(), new TypeMap(), new InjectionMap());
        $context->injections = $this->injections();
        $context->executionScopedClasses[BootErrorTextFixtureScopedStore::class] = true;

        try {
            (new ValidationPhase())->execute($context);
            self::fail('validation passed a worker-scoped class taking an execution-scoped service');
        } catch (InjectionException $e) {
            $this->assertSpeaksToTheApplicationDeveloper($e->getMessage());
        }
    }

    private function assertSpeaksToTheApplicationDeveloper(string $message): void
    {
        // Which class and property, and which implementation is in the way.
        self::assertStringContainsString(BootErrorTextFixtureConsumer::class . '::$draftStore', $message);
        self::assertStringContainsString(BootErrorTextFixtureScopedStore::class, $message);

        // Nothing else that looks like a class: with the reader's own classes
        // removed, every CamelCase name left must be a core attribute.
        $rest = str_replace(
            [BootErrorTextFixtureConsumer::class, BootErrorTextFixtureScopedStore::class, BootErrorTextFixtureStoreInterface::class],
            '',
            $message,
        );
        preg_match_all('/\b[A-Z][a-z0-9]+(?:[A-Z][a-z0-9]+)+\b/', $rest, $matches);
        self::assertNotSame([], $matches[0]);
        foreach (array_unique($matches[0]) as $name) {
            self::assertTrue(
                class_exists('Semitexa\\Core\\Attribute\\' . $name),
                "the boot error names {$name}, which is no attribute an application developer can use: {$message}",
            );
        }
        // Why: the shared instance would capture one execution's clone.
        self::assertStringContainsString("would capture one execution's clone", $message);

        // The two fixes.
        self::assertStringContainsString('#[ExecutionScoped] and take the property with #[InjectAsMutable]', $message);
        self::assertStringContainsString('at call time', $message);
        self::assertStringNotContainsStringIgnoringCase('regression', $message, 'an internal incident is not a fix');
    }

    /**
     * @return array<class-string, array<string, array{kind: string, type: class-string, optional: bool}>>
     */
    private function injections(): array
    {
        return [
            BootErrorTextFixtureConsumer::class => [
                'draftStore' => ['kind' => 'readonly', 'type' => BootErrorTextFixtureStoreInterface::class, 'optional' => false],
            ],
        ];
    }
}
