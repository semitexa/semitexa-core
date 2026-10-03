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
        $errors = $this->gatherAnalyserErrors([self::FIXTURE]);
        // Indexing by line would let a duplicate diagnostic overwrite its twin.
        self::assertCount(2, $errors);

        $reported = [];
        $tips = [];
        foreach ($errors as $error) {
            self::assertSame('semitexa.factoryContract', $error->getIdentifier());
            $reported[$error->getLine()] = $error->getMessage();
            $tips[$error->getLine()] = $error->getTip();
        }
        // A missing or mis-keyed get() is not the narrowing fatal: its own reason (review of core#171).
        $get = (new \ReflectionClass(FactoryContractRule::class))->getConstant('GET_RATIONALE');
        self::assertSame([31 => $get, 36 => $get], $tips);

        self::assertSame([31, 36], array_keys($reported), 'FactoryFixtureChannel (line 22) must pass');
        self::assertStringContainsString('FactoryWithoutGet must declare get()', $reported[31]);
        self::assertStringContainsString('FactoryStringKeyed::get() parameter must be a backed enum', $reported[36]);
    }

    public function testExtendingContractFactoryInterfaceIsRefused(): void
    {
        $errors = $this->gatherAnalyserErrors([self::NARROWING_FIXTURE]);
        $messages = array_map(static fn ($e): string => $e->getMessage(), $errors);
        $narrowing = array_values(array_filter($errors, static fn ($e): bool => str_contains($e->getMessage(), 'must not extend ContractFactoryInterface')));
        self::assertSame((new \ReflectionClass(FactoryContractRule::class))->getConstant('RATIONALE'), $narrowing[0]->getTip() ?? null);

        self::assertCount(1, array_filter(
            $messages,
            static fn (string $m): bool => str_contains($m, 'FactoryNarrowingChannel must not extend ContractFactoryInterface'),
        ), implode("\n", $messages));
    }
}
