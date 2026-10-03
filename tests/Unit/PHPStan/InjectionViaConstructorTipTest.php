<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Semitexa\Core\PHPStan\Rules\InjectionViaConstructorRule;

/**
 * A command's constructor runs (Application::instantiateCommand() uses plain
 * new), so the reason "the container never passes constructor parameters" is
 * false for it. Each kind of class gets the reason that is true for it
 * (review of core#171).
 *
 * @extends RuleTestCase<InjectionViaConstructorRule>
 */
final class InjectionViaConstructorTipTest extends RuleTestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/ConstructorInjectionTips.php';

    protected function getRule(): Rule
    {
        return new InjectionViaConstructorRule();
    }

    protected function setUp(): void
    {
        parent::setUp();
        require_once self::FIXTURE;
    }

    public function testACommandAndAServiceGetTheirOwnReason(): void
    {
        $tips = [];
        foreach ($this->gatherAnalyserErrors([self::FIXTURE]) as $error) {
            $tips[$error->getLine()] = $error->getTip();
        }
        $rule = new \ReflectionClass(InjectionViaConstructorRule::class);

        self::assertSame([
            13 => $rule->getConstant('RATIONALE'),
            21 => $rule->getConstant('COMMAND_RATIONALE'),
        ], $tips);
    }
}
