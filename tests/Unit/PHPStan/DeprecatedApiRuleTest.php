<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Semitexa\Core\PHPStan\Rules\DeprecatedApiRule;

/**
 * The deprecation convention of the stability contract: a deprecated part of
 * the @api surface says from when (the 90 days count from it) and what to use
 * instead. Outside @api nothing is promised, and nothing is checked.
 *
 * @extends RuleTestCase<DeprecatedApiRule>
 */
final class DeprecatedApiRuleTest extends RuleTestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/DeprecatedApi.php';
    private const CLOCK = 'Semitexa\Core\Tests\Unit\PHPStan\Fixtures\DeprecatedApi\ApiClock';
    private const INTERNAL = 'Semitexa\Core\Tests\Unit\PHPStan\Fixtures\DeprecatedApi\Internal';

    protected function getRule(): Rule
    {
        return new DeprecatedApiRule();
    }

    public function testEachBrokenDeprecationIsReportedOnceWithWhatToWrite(): void
    {
        $errors = $this->gatherAnalyserErrors([self::FIXTURE]);
        $reported = [];
        foreach ($errors as $error) {
            self::assertSame('semitexa.deprecatedApi', $error->getIdentifier());
            self::assertSame((new \ReflectionClass(DeprecatedApiRule::class))->getConstant('RATIONALE'), $error->getTip());
            $reported[] = $error->getLine() . ' ' . $error->getMessage();
        }

        self::assertSame([
            '19 ' . self::CLOCK . '::noDate() is @api and @deprecated without a date. Start the tag with the date it was deprecated: `@deprecated <YYYY.MM.DD> Use ApiClock::now() instead.`.',
            '25 ' . self::CLOCK . '::noReplacement() is @api and @deprecated without saying what to use instead. Name it after the date: `@deprecated 2026.10.29 Use ... instead.`',
            '31 ' . self::CLOCK . '::future() is @deprecated from 2999.01.01, a date still to come. Write the date it was deprecated.',
            '37 ' . self::CLOCK . '::attributeDisagrees() says @deprecated 2026.10.29.1052 but #[\Deprecated(since: \'2026.10.01\')]. Give both the same date.',
            '43 ' . self::CLOCK . '::attributeOnly() is @api and #[\Deprecated], with no @deprecated tag. Write `@deprecated <YYYY.MM.DD> <what to use instead>` in its docblock.',
            '66 ' . self::CLOCK . '::OLD is @api and @deprecated without a date. Start the tag with the date it was deprecated: `@deprecated <YYYY.MM.DD> <what to use instead>`.',
            '81 ' . self::INTERNAL . '::markedOnItsOwn() is @api and @deprecated without a date. Start the tag with the date it was deprecated: `@deprecated <YYYY.MM.DD> soon`.',
        ], $reported, 'current(), bothAgree() (a tag over two lines), OldClock and everything outside @api pass');
    }
}
