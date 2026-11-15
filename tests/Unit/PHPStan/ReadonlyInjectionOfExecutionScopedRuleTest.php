<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Semitexa\Core\PHPStan\Rules\ReadonlyInjectionOfExecutionScopedRule;

/**
 * Runs ReadonlyInjectionOfExecutionScopedRule over real classes
 * (tk-inject-from-context-attr): #[InjectAsReadonly] on an #[ExecutionScoped]
 * class, or one a listener attribute makes execution-scoped, is refused with
 * PropertyInjector's own message; a worker-scoped type, #[InjectAsMutable] and
 * an interface type are not judged here.
 *
 * @extends RuleTestCase<ReadonlyInjectionOfExecutionScopedRule>
 */
final class ReadonlyInjectionOfExecutionScopedRuleTest extends RuleTestCase
{
    private const FIXTURES = ['ScopedFixtureService', 'ScopedFixtureListener', 'WorkerFixtureService', 'ReadonlyInjectionHolder'];

    protected function getRule(): Rule
    {
        return new ReadonlyInjectionOfExecutionScopedRule($this->createReflectionProvider());
    }

    public function testAReadonlyInjectionOfAnExecutionScopedClassIsRefused(): void
    {
        $files = [];
        foreach (self::FIXTURES as $class) {
            $file = __DIR__ . '/Fixtures/' . $class . '.php';
            // Autoloaded by PSR-4: a fixture emptied or holding another class fails here.
            self::assertTrue(class_exists('Semitexa\\Core\\Tests\\Unit\\PHPStan\\Fixtures\\' . $class), "{$class} is not declared in {$file}");
            $files[] = $file;
        }

        $errors = $this->gatherAnalyserErrors($files);

        $reported = [];
        foreach ($errors as $error) {
            self::assertSame('semitexa.readonlyInjectionOfExecutionScoped', $error->getIdentifier());
            self::assertSame((new \ReflectionClass(ReadonlyInjectionOfExecutionScopedRule::class))->getConstant('RATIONALE'), $error->getTip());
            $reported[basename($error->getFilePath()) . ':' . $error->getLine()] = $error->getMessage();
        }
        ksort($reported);
        // The alias `InjectAsReadonly as Readonly_` is resolved; $worker, $mutable and $byInterface are not reported; ?ScopedFixtureService is.
        self::assertSame([
            'ReadonlyInjectionHolder.php:13' => 'Cannot inject execution-scoped Semitexa\\Core\\Tests\\Unit\\PHPStan\\Fixtures\\ScopedFixtureService into Semitexa\\Core\\Tests\\Unit\\PHPStan\\Fixtures\\ReadonlyInjectionHolder::$scoped with #[InjectAsReadonly]. Use #[InjectAsMutable] on container-managed objects instead.',
            'ReadonlyInjectionHolder.php:16' => 'Cannot inject execution-scoped Semitexa\\Core\\Tests\\Unit\\PHPStan\\Fixtures\\ScopedFixtureListener into Semitexa\\Core\\Tests\\Unit\\PHPStan\\Fixtures\\ReadonlyInjectionHolder::$listener with #[InjectAsReadonly]. Use #[InjectAsMutable] on container-managed objects instead.',
            'ReadonlyInjectionHolder.php:28' => 'Cannot inject execution-scoped Semitexa\\Core\\Tests\\Unit\\PHPStan\\Fixtures\\ScopedFixtureService into Semitexa\\Core\\Tests\\Unit\\PHPStan\\Fixtures\\ReadonlyInjectionHolder::$nullable with #[InjectAsReadonly]. Use #[InjectAsMutable] on container-managed objects instead.',
        ], $reported);
    }
}
