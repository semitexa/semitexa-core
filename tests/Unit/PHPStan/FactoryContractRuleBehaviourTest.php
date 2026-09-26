<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Semitexa\Core\PHPStan\Rules\FactoryContractRule;

/**
 * Runs FactoryContractRule over real interfaces.
 *
 * The rule used to demand that a Factory* interface extend
 * ContractFactoryInterface AND declare get() with a concrete enum — a
 * combination PHP refuses to load. The accepted shape is a plain interface.
 *
 * @extends RuleTestCase<FactoryContractRule>
 */
final class FactoryContractRuleBehaviourTest extends RuleTestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/FactoryContracts.php';
    private const NARROWING_FIXTURE = __DIR__ . '/Fixtures/FactoryContractsNarrowing.php';

    protected function getRule(): Rule
    {
        return new FactoryContractRule($this->createReflectionProvider());
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Only the loadable fixture: the narrowing one is a fatal on load.
        require_once self::FIXTURE;
    }

    public function testThePlainEnumKeyedInterfaceIsAcceptedAndBrokenOnesAreNot(): void
    {
        $reported = [];
        foreach ($this->gatherAnalyserErrors([self::FIXTURE]) as $error) {
            self::assertSame('semitexa.factoryContract', $error->getIdentifier());
            $reported[$error->getLine()] = $error->getMessage();
        }

        self::assertSame([31, 36], array_keys($reported), 'FactoryFixtureChannel (line 22) must pass');
        self::assertStringContainsString('FactoryWithoutGet must declare get()', $reported[31]);
        self::assertStringContainsString('FactoryStringKeyed::get() parameter must be a backed enum', $reported[36]);
    }

    public function testExtendingContractFactoryInterfaceIsRefused(): void
    {
        $errors = $this->gatherAnalyserErrors([self::NARROWING_FIXTURE]);
        $messages = array_map(static fn ($e): string => $e->getMessage(), $errors);

        self::assertCount(1, array_filter(
            $messages,
            static fn (string $m): bool => str_contains($m, 'FactoryNarrowingChannel must not extend ContractFactoryInterface'),
        ), implode("\n", $messages));
    }
}
