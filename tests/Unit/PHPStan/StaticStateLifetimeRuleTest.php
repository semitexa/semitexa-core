<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Semitexa\Core\PHPStan\Rules\StaticStateLifetimeRule;

/**
 * Runs StaticStateLifetimeRule over real code and checks what it reports.
 *
 * The rule exists because the worst bugs in a long-lived Swoole worker share
 * one root cause — state living in a longer scope than its data — and nothing
 * in the code told a correctly worker-wide static from a leak. These cases pin
 * both halves: every undeclared static that can hold an object or array is
 * reported, and every documented exemption stays silent, so the rule neither
 * goes quiet nor starts demanding attributes on flags.
 *
 * @extends RuleTestCase<StaticStateLifetimeRule>
 */
final class StaticStateLifetimeRuleTest extends RuleTestCase
{
    /** One declaration per file under its PSR-4 path: the autoloader finds them. */
    private const FIXTURES = ['UndeclaredStaticState', 'DeclaredOrExemptStaticState', 'StaticStateScope', 'RequestScopedStaticState', 'StaticStateInTrait', 'AliasedWorkerStateAttribute', 'AliasedRegistryStaticState', 'ForeignWorkerStateAttribute'];

    protected function getRule(): Rule
    {
        return new StaticStateLifetimeRule($this->createReflectionProvider());
    }

    /**
     * The fixture files, checked to exist. Once they were several classes in
     * one file, which PSR-4 cannot autoload: a rule that sees no class reports
     * nothing, a green test proving the opposite of what it claims, so the file
     * was required by hand. One per file, the autoloader finds each.
     *
     * @return list<string>
     */
    private static function fixtures(): array
    {
        $files = array_map(static fn (string $class): string => __DIR__ . '/Fixtures/' . $class . '.php', self::FIXTURES);
        foreach ($files as $file) {
            self::assertFileExists($file, 'the fixtures moved: this test would check nothing');
        }
        foreach ($files as $file) {
            // Autoloaded by PSR-4: a file emptied or holding another class fails here,
            // so a fixture expected to report nothing cannot pass by being empty.
            $name = 'Semitexa\\Core\\Tests\\Unit\\PHPStan\\Fixtures\\' . basename($file, '.php');
            self::assertTrue(class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name), "{$name} is not declared in {$file}");
        }

        return $files;
    }

    /**
     * @return array<string, string> File.php:line => message
     */
    private function reported(): array
    {
        $reported = [];
        foreach ($this->gatherAnalyserErrors(self::fixtures()) as $error) {
            self::assertSame('semitexa.staticStateLifetime', $error->getIdentifier());
            $at = basename($error->getFilePath()) . ':' . $error->getLine();
            // Two properties on one line report twice; a keyed map would keep one.
            self::assertArrayNotHasKey($at, $reported, 'a second diagnostic on ' . $at . ' would be hidden');
            $reported[$at] = $error->getMessage();
        }
        ksort($reported);

        return $reported;
    }

    public function testItFlagsExactlyTheUndeclaredStaticsThatCanHoldObjectsOrArrays(): void
    {
        // Array, nullable object, untyped (mixed), a `static $x = null` local,
        // a trait's static, and a static carrying a foreign attribute that is
        // merely named WorkerState. Nothing from the exempt or declared classes,
        // including the aliased attribute and the aliased registry call.
        self::assertSame(['ForeignWorkerStateAttribute.php:16', 'StaticStateInTrait.php:16', 'UndeclaredStaticState.php:22', 'UndeclaredStaticState.php:24', 'UndeclaredStaticState.php:26', 'UndeclaredStaticState.php:30'], array_keys($this->reported()));
    }

    public function testTheMessageSaysWhatToDoAndWhy(): void
    {
        $property = $this->reported()['UndeclaredStaticState.php:22'];

        self::assertStringStartsWith(
            'Static property Semitexa\Core\Tests\Unit\PHPStan\Fixtures\UndeclaredStaticState::$decisions '
            . '(array<string, object>) is mutable state shared by every request and coroutine',
            $property,
        );
        self::assertStringContainsString('Swoole worker', $property, 'names why: the worker lifetime');
        self::assertStringContainsString('#[WorkerState(reason:', $property, 'names the declaration');
        self::assertStringContainsString('PerRequestStateRegistry::register()', $property, 'names request scope');
        self::assertStringContainsString('CoroutineLocal', $property, 'names coroutine scope');
    }

    /**
     * A static local cannot carry an attribute, so the message must not tell
     * the reader to add one to it — it says where to move the state instead.
     */
    public function testAStaticLocalIsToldToMoveNotToAnnotate(): void
    {
        $local = $this->reported()['UndeclaredStaticState.php:30'];

        self::assertStringStartsWith(
            'Static variable $cache in Semitexa\Core\Tests\Unit\PHPStan\Fixtures\UndeclaredStaticState::memo()',
            $local,
        );
        self::assertStringContainsString('Move it to a static property with #[WorkerState(', $local);
    }
}
