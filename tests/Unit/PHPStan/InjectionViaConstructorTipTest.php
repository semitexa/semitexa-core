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
    /** One declaration per file under its PSR-4 path: the autoloader finds them. */
    private const FIXTURES = __DIR__ . '/Fixtures/Tips';

    protected function getRule(): Rule
    {
        return new InjectionViaConstructorRule();
    }

    public function testACommandAndAServiceGetTheirOwnReason(): void
    {
        $tips = [];
        $files = glob(self::FIXTURES . '/*.php');
        self::assertIsArray($files);
        self::assertCount(2, $files, 'the fixtures moved: this test would check nothing');
        foreach ($files as $file) {
            // Autoloaded by PSR-4: a file emptied or holding another class fails here,
            // so a fixture expected to report nothing cannot pass by being empty.
            $name = 'Semitexa\\Core\\Tests\\Unit\\PHPStan\\Fixtures\\Tips\\' . basename($file, '.php');
            self::assertTrue(class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name), "{$name} is not declared in {$file}");
        }
        foreach ($this->gatherAnalyserErrors($files) as $error) {
            $tips[basename($error->getFilePath()) . ':' . $error->getLine()] = $error->getTip();
        }
        ksort($tips);
        $rule = new \ReflectionClass(InjectionViaConstructorRule::class);

        self::assertSame([
            'TipCommandWithConstructorInjection.php:13' => $rule->getConstant('COMMAND_RATIONALE'),
            'TipServiceWithConstructorInjection.php:13' => $rule->getConstant('RATIONALE'),
        ], $tips);
    }
}
