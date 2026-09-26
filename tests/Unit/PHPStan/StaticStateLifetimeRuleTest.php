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
    private const FIXTURE = __DIR__ . '/Fixtures/StaticStateLifetimes.php';

    protected function getRule(): Rule
    {
        return new StaticStateLifetimeRule($this->createReflectionProvider());
    }

    /**
     * Several classes in one file: PSR-4 cannot autoload them, and a rule that
     * sees no class reports nothing — a green test proving the opposite of what
     * it claims. Loading the file lets PHPStan's reflection find them.
     */
    protected function setUp(): void
    {
        parent::setUp();

        require_once self::FIXTURE;
    }

    /**
     * @return array<int, string> line => message
     */
    private function reported(): array
    {
        $reported = [];
        foreach ($this->gatherAnalyserErrors([self::FIXTURE]) as $error) {
            self::assertSame('semitexa.staticStateLifetime', $error->getIdentifier());
            $reported[$error->getLine()] = $error->getMessage();
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
        self::assertSame([20, 22, 24, 28, 85, 118], array_keys($this->reported()));
    }

    public function testTheMessageSaysWhatToDoAndWhy(): void
    {
        $property = $this->reported()[20];

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
        $local = $this->reported()[28];

        self::assertStringStartsWith(
            'Static variable $cache in Semitexa\Core\Tests\Unit\PHPStan\Fixtures\UndeclaredStaticState::memo()',
            $local,
        );
        self::assertStringContainsString('Move it to a static property with #[WorkerState(', $local);
    }
}
