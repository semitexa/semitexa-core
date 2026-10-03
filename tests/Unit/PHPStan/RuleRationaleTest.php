<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan;

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\TipRuleError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Semitexa\Core\PHPStan\Rules\DisallowErrorLogRule;

/**
 * Every Semitexa rule says why it exists, at the moment it fires.
 *
 * The reason a rule exists — the approach that was tried and what it broke —
 * used to live in docblocks, commit messages and chat threads, which is to say
 * nowhere an agent arguing with the rule would read it. A rule now carries it
 * as a RATIONALE constant and attaches it to every report as the PHPStan tip:
 * `composer phpstan` prints it, ai:verify forwards it as `rationale`.
 *
 * The shape is pinned so the honest answer stays available: either what taught
 * us ("Learned <date>: ...") or that nothing did ("no incident on record").
 * A rule that is policy rather than scar tissue should say so; a reader weighs
 * the two differently, and should.
 */
final class RuleRationaleTest extends TestCase
{
    private const RULES_DIR = __DIR__ . '/../../../src/PHPStan/Rules';

    /** A cause, then either the history behind it or the admission there is none. */
    private const SHAPE = '/^Why: \S.{60,}(Learned (\d{4}-\d{2}|in )|no incident on record)/s';

    #[Test]
    public function every_report_of_every_rule_carries_a_rationale(): void
    {
        $files = glob(self::RULES_DIR . '/*Rule.php');
        self::assertIsArray($files);
        self::assertGreaterThanOrEqual(27, count($files), 'the rules directory moved or the glob stopped matching — this test would be vacuous');

        $missing = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $rule = basename($file, '.php');

            $identifiers = preg_match_all("/->identifier\('semitexa\.\w+'\)/", $source);
            $explained = preg_match_all("/->tip\(self::(\w*RATIONALE)\)\s*->identifier\('semitexa\.\w+'\)/", $source, $constants);
            if ($identifiers === 0 || $explained !== $identifiers) {
                $missing[] = sprintf('%s: %d of %d reports carry ->tip(self::*RATIONALE)', $rule, (int) $explained, (int) $identifiers);
                continue;
            }

            $class = new ReflectionClass('Semitexa\\Core\\PHPStan\\Rules\\' . $rule);
            foreach (array_unique($constants[1]) as $constant) {
                $text = $class->getConstant($constant);
                if (!is_string($text) || preg_match(self::SHAPE, $text) !== 1) {
                    $missing[] = sprintf('%s::%s does not read "Why: ... Learned <date>: ..." or "... no incident on record"', $rule, $constant);
                }
            }
        }

        self::assertSame([], $missing);
    }

    #[Test]
    public function the_rationale_reaches_the_report(): void
    {
        $errors = (new DisallowErrorLogRule())->processNode(
            new FuncCall(new Name('error_log')),
            $this->createStub(Scope::class),
        );

        self::assertCount(1, $errors);
        self::assertInstanceOf(TipRuleError::class, $errors[0]);
        self::assertSame(
            (new ReflectionClass(DisallowErrorLogRule::class))->getConstant('RATIONALE'),
            $errors[0]->getTip(),
        );
    }
}
