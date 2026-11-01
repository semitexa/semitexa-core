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
            $reported[] = basename($error->getTraitFilePath() ?? $error->getFilePath()) . ':' . $error->getLine() . ' ' . $error->getMessage();
        }

        self::assertSame([
            'ApiHelper.php:11 ' . self::NS . 'ApiHelper::old() is @api and @deprecated without a date. Start the tag with the date it was deprecated: `@deprecated <YYYY.MM.DD> soon`.',
            'PromotesApi.php:14 ' . self::NS . 'PromotesApi::$count is @api and @deprecated without a date. Start the tag with the date it was deprecated: `@deprecated <YYYY.MM.DD> soon`.',
        ], array_values(array_unique($reported)), 'current() and dated() pass; Unmarked promises nothing');
        // Once per class using the trait, as PHPStan reports every error in a
        // trait ("in context of class …"): ApiHelper has two users.
        self::assertCount(3, $reported);
    }
}
