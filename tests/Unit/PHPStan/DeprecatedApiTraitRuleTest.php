<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Semitexa\Core\PHPStan\Rules\DeprecatedApiTraitRule;

/**
 * DeprecatedApiRule listens on InClassNode, which PHPStan never gives a
 * trait: an @api member a trait declares went unchecked (CodeRabbit on
 * core#194, tk-deprecated-api-traits).
 *
 * @extends RuleTestCase<DeprecatedApiTraitRule>
 */
final class DeprecatedApiTraitRuleTest extends RuleTestCase
{
    /** One declaration per file, under its PSR-4 path: the autoloader finds each, nothing is required by hand. */
    private const FIXTURES = __DIR__ . '/Fixtures/DeprecatedApiTraits';
    private const NS = 'Semitexa\Core\Tests\Unit\PHPStan\Fixtures\DeprecatedApiTraits\\';

    protected function getRule(): Rule
    {
        return new DeprecatedApiTraitRule(now: '2026.11.01.1200');
    }

    public function testAnApiTraitAndAnApiPropertyItPromotesAreCheckedUnderTheTraitsName(): void
    {
        $reported = [];
        $files = glob(self::FIXTURES . '/*.php');
        self::assertIsArray($files);
        self::assertCount(5, $files, 'the fixtures moved: this test would check nothing');
        foreach ($files as $file) {
            // Autoloaded by PSR-4: a file emptied or holding another class fails here,
            // so a fixture expected to report nothing cannot pass by being empty.
            $name = 'Semitexa\\Core\\Tests\\Unit\\PHPStan\\Fixtures\\DeprecatedApiTraits\\' . basename($file, '.php');
            self::assertTrue(class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name), "{$name} is not declared in {$file}");
        }
        foreach ($this->gatherAnalyserErrors($files) as $error) {
            self::assertSame('semitexa.deprecatedApi', $error->getIdentifier());
            // The trait's own file and line, and the class it was analysed in.
            $reported[] = sprintf('%s:%d in %s: %s', basename($error->getTraitFilePath() ?? $error->getFilePath()), $error->getLine(), basename($error->getFilePath()), $error->getMessage());
        }
        sort($reported);

        $const = self::NS . 'ApiHelper::OLD_LIMIT is @api and @deprecated without a date. Start the tag with the date it was deprecated: `@deprecated <YYYY.MM.DD> soon`.';
        $old = self::NS . 'ApiHelper::old() is @api and @deprecated without a date. Start the tag with the date it was deprecated: `@deprecated <YYYY.MM.DD> soon`.';
        $count = self::NS . 'PromotesApi::$count is @api and @deprecated without a date. Start the tag with the date it was deprecated: `@deprecated <YYYY.MM.DD> soon`.';
        // Once per class using the trait, as PHPStan reports every error in a
        // trait ("in context of class …"): ApiHelper has two users, PromotesApi one.
        // verify:accept-test-change assertCount(3) is folded into this exact sorted list, which pins each report and the class it ran in, so a wrong count for any one of them fails
        self::assertSame([
            'ApiHelper.php:11 in AlsoUsesTheHelper.php: ' . $const,
            'ApiHelper.php:11 in UsesAll.php: ' . $const,
            'ApiHelper.php:14 in AlsoUsesTheHelper.php: ' . $old,
            'ApiHelper.php:14 in UsesAll.php: ' . $old,
            'PromotesApi.php:14 in UsesAll.php: ' . $count,
        ], $reported, 'current() and dated() pass; Unmarked promises nothing');
    }
}
