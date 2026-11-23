<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Semitexa\Core\PHPStan\Rules\WorkerServiceCallerStateRule;

/**
 * Runs WorkerServiceCallerStateRule over real code and checks what it reports.
 *
 * Proven 22.11.2026: an #[AsService] with `protected string $tenant`, written
 * and read back by handlers, answered 168 to 184 of 400 concurrent requests
 * with the other tenant's name, while lint:di and PHPStan both passed. These
 * cases pin both halves of the rule that now reports it: the caller's value
 * kept on a worker-shared service is flagged, and every way of saying "this is
 * not a caller's value" stays silent.
 *
 * @extends RuleTestCase<WorkerServiceCallerStateRule>
 */
final class WorkerServiceCallerStateRuleTest extends RuleTestCase
{
    /** One declaration per file under its PSR-4 path: the autoloader finds them. */
    private const FIXTURES = ['CallerStateSharedMemo', 'CallerStateDeclaredOrOwned', 'CallerStateScopedMemo', 'CallerStatePlainObject'];

    protected function getRule(): Rule
    {
        return new WorkerServiceCallerStateRule();
    }

    /**
     * The fixture files, checked to exist and to declare their class: a rule
     * that sees no class reports nothing, and a "stays silent" case would pass
     * by being empty.
     *
     * @return list<string>
     */
    private static function fixtures(): array
    {
        $files = [];
        foreach (self::FIXTURES as $class) {
            $file = __DIR__ . '/Fixtures/' . $class . '.php';
            self::assertFileExists($file, 'the fixtures moved: this test would check nothing');
            self::assertTrue(class_exists('Semitexa\\Core\\Tests\\Unit\\PHPStan\\Fixtures\\' . $class), "{$class} is not declared in {$file}");
            $files[] = $file;
        }

        return $files;
    }

    /**
     * @return array<string, string> File.php:line => message
     */
    private function reported(): array
    {
        $errors = $this->gatherAnalyserErrors(self::fixtures());
        // A rule that reports nothing would pass every "is not flagged" case below.
        self::assertNotSame([], $errors, 'the rule reports nothing at all: a silent case proves nothing');

        $reported = [];
        foreach ($errors as $error) {
            self::assertSame('semitexa.workerServiceCallerState', $error->getIdentifier());
            $at = basename($error->getFilePath()) . ':' . $error->getLine();
            if (isset($reported[$at])) {
                self::fail('a second diagnostic on ' . $at . ' would be hidden by the keyed map');
            }
            $reported[$at] = $error->getMessage();
        }
        ksort($reported);

        return $reported;
    }

    public function testItFlagsACallersValueKeptOnAWorkerSharedService(): void
    {
        // The proven case: $tenant set from a handler-called method.
        self::assertArrayHasKey('CallerStateSharedMemo.php:32', $this->reported());
    }

    public function testItFlagsAnArrayElementAssignedFromAParameter(): void
    {
        $reported = $this->reported();

        // `$this->notes[$key] = trim($text)`: a keyed element, the parameter inside a call.
        self::assertArrayHasKey('CallerStateSharedMemo.php:37', $reported);
        // `$this->seen[] = $who`: an appended element.
        self::assertArrayHasKey('CallerStateSharedMemo.php:42', $reported);
    }

    /**
     * The whole picture in one assertion, so a rule that starts reporting
     * anything else fails here: the four properties of the shared memo, once
     * each (the second `$this->seen[] =` on line 43 adds nothing), and nothing
     * from the declared, container-owned, scoped or plain classes.
     */
    public function testItReportsEachPropertyOnceAndNothingElse(): void
    {
        self::assertSame(
            ['CallerStateSharedMemo.php:32', 'CallerStateSharedMemo.php:37', 'CallerStateSharedMemo.php:42', 'CallerStateSharedMemo.php:48'],
            array_keys($this->reported()),
        );
    }

    public function testADeclaredWorkerStatePropertyIsNotFlagged(): void
    {
        self::assertSame([], $this->reportedFor('$registry'));
    }

    public function testInjectedAndConfigPropertiesAreNotFlagged(): void
    {
        self::assertSame([], $this->reportedFor('$readonlyDependency'));
        self::assertSame([], $this->reportedFor('$mutableDependency'));
        self::assertSame([], $this->reportedFor('$limit'));
    }

    public function testAssignmentsInTheConstructorAndInInitializeAreNotFlagged(): void
    {
        self::assertSame([], $this->reportedFor('$prefix'));
        self::assertSame([], $this->reportedFor('$suffix'));
    }

    public function testAnExecutionScopedClassIsNotFlagged(): void
    {
        self::assertSame([], $this->reportedFor('CallerStateScopedMemo::'));
    }

    public function testTheMessageNamesTheClassThePropertyTheHazardAndBothFixes(): void
    {
        $message = $this->reported()['CallerStateSharedMemo.php:32'];

        self::assertStringStartsWith(
            'Property Semitexa\Core\Tests\Unit\PHPStan\Fixtures\CallerStateSharedMemo::$tenant is assigned from '
            . 'parameter $tenant of remember()',
            $message,
        );
        self::assertStringContainsString('the value a caller passed would be shared by every request on the worker', $message);
        self::assertStringContainsString('read the context at call time', $message, 'the first fix');
        self::assertStringContainsString("#[WorkerState('why')]", $message, 'the second fix');
    }

    /**
     * The reports that mention the fragment. The silent cases are only worth
     * anything while the rule reports at all, which reported() asserts first.
     *
     * @return list<string>
     */
    private function reportedFor(string $fragment): array
    {
        return array_values(array_filter(
            $this->reported(),
            static fn (string $message): bool => str_contains($message, $fragment),
        ));
    }
}
