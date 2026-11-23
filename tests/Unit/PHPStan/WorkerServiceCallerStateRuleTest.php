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
 * A silent case proves something only while its fixture still holds the write
 * it exists for, so each one first asserts that write, as the exact method.
 *
 * @extends RuleTestCase<WorkerServiceCallerStateRule>
 */
final class WorkerServiceCallerStateRuleTest extends RuleTestCase
{
    /** One declaration per file under its PSR-4 path: the autoloader finds them. */
    private const FIXTURES = [
        'CallerStateSharedMemo',
        'CallerStateDeclaredOrOwned',
        'CallerStateScopedMemo',
        'CallerStatePlainObject',
        'CallerStateForeignScoped',
        'CallerStateMarksAService',
        'CallerStateImpliedService',
        'CallerStateImpliedScoped',
        'CallerStateUnknownAttribute',
        'CallerStateInitializeSetter',
        'CallerStateNestedClass',
        'CallerStateReadsAndScopes',
        'CallerStateAuthHandler',
        'CallerStateSlotHandler',
        'CallerStateLiteralPropertyName',
    ];

    /** The one write every "same class, different marking" fixture holds. */
    private const REMEMBER = <<<'PHP'
            public function remember(string $tenant): void
            {
                $this->tenant = $tenant;
            }
        PHP;

    protected function getRule(): Rule
    {
        return new WorkerServiceCallerStateRule($this->createReflectionProvider());
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
            $file = self::fixture($class);
            self::assertTrue(class_exists('Semitexa\\Core\\Tests\\Unit\\PHPStan\\Fixtures\\' . $class), "{$class} is not declared in {$file}");
            $files[] = $file;
        }

        return $files;
    }

    private static function fixture(string $class): string
    {
        $file = __DIR__ . '/Fixtures/' . $class . '.php';
        self::assertFileExists($file, 'the fixtures moved: this test would check nothing');

        return $file;
    }

    /**
     * The fixture really holds the code the case is about. Compared as whole
     * lines, indentation aside, so the statement in a comment, or in another
     * method, does not count.
     */
    private static function assertFixtureHolds(string $class, string $code): void
    {
        $flatten = static fn (string $php): string => implode("\n", array_map(trim(...), explode("\n", trim($php))));
        $source = file_get_contents(self::fixture($class));
        self::assertIsString($source);

        self::assertStringContainsString(
            "\n" . $flatten($code) . "\n",
            "\n" . $flatten($source) . "\n",
            "{$class} lost the write this case exists for: its silence would prove nothing",
        );
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

    /**
     * The lines reported in one fixture file.
     *
     * @return list<int>
     */
    private function linesReportedIn(string $class): array
    {
        $lines = [];
        foreach (array_keys($this->reported()) as $at) {
            [$file, $line] = explode(':', $at);
            if ($file === $class . '.php') {
                $lines[] = (int) $line;
            }
        }
        sort($lines);

        return $lines;
    }

    public function testItFlagsACallersValueKeptOnAWorkerSharedService(): void
    {
        // The proven case: $tenant set from a handler-called method.
        self::assertFixtureHolds('CallerStateSharedMemo', self::REMEMBER);
        self::assertContains(32, $this->linesReportedIn('CallerStateSharedMemo'));
    }

    public function testItFlagsAnArrayElementAssignedFromAParameter(): void
    {
        $lines = $this->linesReportedIn('CallerStateSharedMemo');

        // `$this->notes[$key] = trim($text)`: a keyed element, the parameter inside a call.
        self::assertContains(37, $lines);
        // `$this->seen[] = $who`: an appended element.
        self::assertContains(42, $lines);
    }

    /**
     * The four properties of the shared memo, once each: the second
     * `$this->seen[] =` on line 43 adds nothing.
     */
    public function testItReportsEachPropertyOnce(): void
    {
        self::assertSame([32, 37, 42, 48], $this->linesReportedIn('CallerStateSharedMemo'));
    }

    public function testADeclaredWorkerStatePropertyIsNotFlagged(): void
    {
        self::assertFixtureHolds('CallerStateDeclaredOrOwned', <<<'PHP'
            public function register(string $name, object $entry): void
            {
                $this->registry[$name] = $entry;
            }
            PHP);
        self::assertSame([], $this->reportedFor('::$registry '));
    }

    public function testInjectedAndConfigPropertiesAreNotFlagged(): void
    {
        self::assertFixtureHolds('CallerStateDeclaredOrOwned', <<<'PHP'
            public function swap(WorkerFixtureService $readonly, ScopedFixtureService $mutable, int $limit): void
            {
                $this->readonlyDependency = $readonly;
                $this->mutableDependency = $mutable;
                $this->limit = $limit;
            }
            PHP);
        self::assertSame([], $this->reportedFor('::$readonlyDependency '));
        self::assertSame([], $this->reportedFor('::$mutableDependency '));
        self::assertSame([], $this->reportedFor('::$limit '));
    }

    /**
     * The container refuses a constructor with parameters on a container-managed
     * class and builds a generated Factory* class once, so no request reaches one.
     */
    public function testAnAssignmentInTheConstructorIsNotFlagged(): void
    {
        self::assertFixtureHolds('CallerStateDeclaredOrOwned', <<<'PHP'
            public function __construct(string $prefix = '')
            {
                $this->prefix = $prefix;
            }
            PHP);
        self::assertSame([], $this->reportedFor('::$prefix '));
    }

    /** The real hook takes no parameter, so there is nothing it could keep. */
    public function testTheContainersInitializeHookIsNotFlagged(): void
    {
        self::assertFixtureHolds('CallerStateDeclaredOrOwned', <<<'PHP'
            public function initialize(): void
            {
                $this->suffix = strtolower('SUFFIX');
            }
            PHP);
        self::assertSame([], $this->linesReportedIn('CallerStateDeclaredOrOwned'));
    }

    /** A method merely named initialize is a setter any handler may call. */
    public function testAMethodNamedInitializeThatTakesAParameterIsFlagged(): void
    {
        self::assertFixtureHolds('CallerStateInitializeSetter', <<<'PHP'
            public function initialize(string $tenant): void
            {
                $this->tenant = $tenant;
            }
            PHP);
        self::assertSame([23], $this->linesReportedIn('CallerStateInitializeSetter'));
    }

    public function testAnExecutionScopedClassIsNotFlagged(): void
    {
        self::assertFixtureHolds('CallerStateScopedMemo', self::REMEMBER);
        self::assertFixtureHolds('CallerStateScopedMemo', "#[AsService]\n#[ExecutionScoped]\nfinal class CallerStateScopedMemo");
        self::assertSame([], $this->linesReportedIn('CallerStateScopedMemo'));
    }

    /** The container knows only its own attribute: any other one of that name scopes nothing. */
    public function testAForeignAttributeNamedExecutionScopedDoesNotExempt(): void
    {
        self::assertFixtureHolds('CallerStateForeignScoped', self::REMEMBER);
        self::assertFixtureHolds('CallerStateForeignScoped', "#[AsService]\n#[\\ExecutionScoped]\nfinal class CallerStateForeignScoped");
        self::assertSame([24], $this->linesReportedIn('CallerStateForeignScoped'));
    }

    /** ServiceRegistrationPhase registers the carrier of an ImpliesService attribute as a service. */
    public function testAClassWhoseAttributeImpliesAServiceIsFlagged(): void
    {
        self::assertFixtureHolds('CallerStateImpliedService', self::REMEMBER);
        self::assertFixtureHolds('CallerStateImpliedService', "#[CallerStateMarksAService]\nfinal class CallerStateImpliedService");
        self::assertSame([21], $this->linesReportedIn('CallerStateImpliedService'));
    }

    public function testAnImpliedServiceThatIsExecutionScopedIsNotFlagged(): void
    {
        self::assertFixtureHolds('CallerStateImpliedScoped', self::REMEMBER);
        self::assertSame([], $this->linesReportedIn('CallerStateImpliedScoped'));
    }

    /**
     * InjectionAnalyzer puts auth and slot handlers in the execution-scoped set,
     * so the same write that is reported on the plain service is not on them.
     */
    public function testAuthAndSlotHandlersAreExecutionScopedAndNotFlagged(): void
    {
        self::assertFixtureHolds('CallerStateAuthHandler', self::REMEMBER);
        self::assertFixtureHolds('CallerStateAuthHandler', "#[AsService]\n#[\\Semitexa\\Auth\\Attribute\\AsAuthHandler]\nfinal class CallerStateAuthHandler");
        self::assertSame([], $this->linesReportedIn('CallerStateAuthHandler'));

        self::assertFixtureHolds('CallerStateSlotHandler', self::REMEMBER);
        self::assertFixtureHolds('CallerStateSlotHandler', "#[AsService]\n#[\\Semitexa\\Ssr\\Attribute\\AsSlotHandler]\nfinal class CallerStateSlotHandler");
        self::assertSame([], $this->linesReportedIn('CallerStateSlotHandler'));

        self::assertContains(32, $this->linesReportedIn('CallerStateSharedMemo'), 'the plain service stays reported');
    }

    /**
     * `$this->{'tenant'} = $tenant` on line 27 and `[$this->{'region'}] =
     * [$region]` on line 32 are the properties they name; `$this->{$name}` on
     * line 37 cannot be resolved and is skipped.
     */
    public function testAPropertyNamedByALiteralStringIsFlagged(): void
    {
        self::assertSame([27, 32], $this->linesReportedIn('CallerStateLiteralPropertyName'));
    }

    public function testAClassTheContainerDoesNotManageIsNotFlagged(): void
    {
        self::assertFixtureHolds('CallerStatePlainObject', self::REMEMBER);
        self::assertSame([], $this->linesReportedIn('CallerStatePlainObject'));

        // An attribute whose class does not exist makes no service, and does not break the rule.
        self::assertFixtureHolds('CallerStateUnknownAttribute', self::REMEMBER);
        self::assertSame([], $this->linesReportedIn('CallerStateUnknownAttribute'));
    }

    /**
     * make(): the anonymous class's constructor writes its own $tenant on line
     * 32, the service writes its own on line 35. describe(): only the anonymous
     * class writes, so nothing. hold(): the argument of `new class (...)` is
     * read by the method, line 54.
     */
    public function testAClassDeclaredInsideAMethodIsNotTheService(): void
    {
        self::assertFixtureHolds('CallerStateNestedClass', <<<'PHP'
            public function __construct(string $tenant)
            {
                $this->tenant = $tenant;
            }
            };
            $this->tenant = $tenant;
            PHP);
        self::assertFixtureHolds('CallerStateNestedClass', <<<'PHP'
            public function relabel(string $label): void
            {
                $this->label = $label;
            }
            PHP);
        self::assertSame([35, 54], $this->linesReportedIn('CallerStateNestedClass'));
    }

    /**
     * Reported: trim($tenant) 44, the compound assignment 56, both destructuring
     * targets 67 and 68, the capturing closure 83, arrow function 97 and static
     * closure 103, the write inside a closure 112 and inside an arrow function
     * 139. Not reported: `($tenant = 'default')` 38, the destructured parameters
     * 50, the shadowing closure 75 and arrow function 91, the closure's own
     * parameter 121, the closure without `use` 131.
     */
    public function testOnlyAParameterOfTheMethodThatIsReadCounts(): void
    {
        self::assertFixtureHolds('CallerStateReadsAndScopes', <<<'PHP'
            public function fixed(string $tenant): void
            {
                $this->fixed = ($tenant = 'default');
            }
            PHP);
        self::assertFixtureHolds('CallerStateReadsAndScopes', <<<'PHP'
            public function pair(string $left, string $right): void
            {
                $this->pair = ([$left, $right] = ['a', 'b']);
            }
            PHP);
        self::assertFixtureHolds('CallerStateReadsAndScopes', <<<'PHP'
            public function shadowing(string $tenant): void
            {
                $this->shadowing = function (string $tenant): string {
                    return $tenant;
                };
            }
            PHP);
        self::assertFixtureHolds('CallerStateReadsAndScopes', <<<'PHP'
            public function arrowShadowing(string $tenant): void
            {
                $this->arrowShadowing = fn (string $tenant): string => $tenant;
            }
            PHP);
        self::assertFixtureHolds('CallerStateReadsAndScopes', <<<'PHP'
            public function viaClosureParameter(string $tenant): void
            {
                $write = function (string $tenant): void {
                    $this->viaClosureParameter = $tenant;
                };
            PHP);
        self::assertFixtureHolds('CallerStateReadsAndScopes', <<<'PHP'
            public function viaUnusedClosure(string $tenant): void
            {
                $write = function (): void {
                    $tenant = 'own';
                    $this->viaUnusedClosure = $tenant;
                };
            PHP);

        self::assertSame([44, 56, 67, 68, 83, 97, 103, 112, 139], $this->linesReportedIn('CallerStateReadsAndScopes'));
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
