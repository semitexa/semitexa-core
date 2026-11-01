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
        foreach ($this->gatherAnalyserErrors($files) as $error) {
            self::assertSame('semitexa.deprecatedApi', $error->getIdentifier());
            // The trait's own file and line, and the class it was analysed in.
            $reported[] = sprintf('%s:%d in %s: %s', basename($error->getTraitFilePath() ?? $error->getFilePath()), $error->getLine(), basename($error->getFilePath()), $error->getMessage());
        }
        sort($reported);

        $old = self::NS . 'ApiHelper::old() is @api and @deprecated without a date. Start the tag with the date it was deprecated: `@deprecated <YYYY.MM.DD> soon`.';
        $count = self::NS . 'PromotesApi::$count is @api and @deprecated without a date. Start the tag with the date it was deprecated: `@deprecated <YYYY.MM.DD> soon`.';
        // Once per class using the trait, as PHPStan reports every error in a
        // trait ("in context of class …"): ApiHelper has two users, PromotesApi one.
        // verify:accept-test-change assertCount(3) is folded into this exact sorted list, which pins each report and the class it ran in, so a wrong count for any one of them fails
        self::assertSame([
            'ApiHelper.php:11 in AlsoUsesTheHelper.php: ' . $old,
            'ApiHelper.php:11 in UsesAll.php: ' . $old,
            'PromotesApi.php:14 in UsesAll.php: ' . $count,
        ], $reported, 'current() and dated() pass; Unmarked promises nothing');
    }
}
