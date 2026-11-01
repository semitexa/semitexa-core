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
    /** One declaration per file under its PSR-4 path: the autoloader finds them. */
    private const FIXTURES = ['FixtureChannelKind', 'FixtureChannel', 'FactoryFixtureChannel', 'FactoryWithoutGet', 'FactoryStringKeyed'];
    private const NARROWING_FIXTURE = __DIR__ . '/Fixtures/FactoryContractsNarrowing.php';

    protected function getRule(): Rule
    {
        return new FactoryContractRule($this->createReflectionProvider());
    }

    /**
     * The fixture files, checked to exist. The narrowing fixture is not among
     * them and is never loaded: declaring it is a fatal on load, so PHPStan
     * reads it statically, by its own path.
     *
     * @return list<string>
     */
    private static function fixtures(): array
    {
        $files = array_map(static fn (string $class): string => __DIR__ . '/Fixtures/' . $class . '.php', self::FIXTURES);
        foreach ($files as $file) {
            self::assertFileExists($file, 'the fixtures moved: this test would check nothing');
        }
        foreach ($files as $file) {
            // Autoloaded by PSR-4: a file emptied or holding another class fails here,
            // so a fixture expected to report nothing cannot pass by being empty.
            $name = 'Semitexa\\Core\\Tests\\Unit\\PHPStan\\Fixtures\\' . basename($file, '.php');
            self::assertTrue(class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name), "{$name} is not declared in {$file}");
        }

        return $files;
    }

    public function testThePlainEnumKeyedInterfaceIsAcceptedAndBrokenOnesAreNot(): void
    {
        $errors = $this->gatherAnalyserErrors(self::fixtures());
        // Indexing by line would let a duplicate diagnostic overwrite its twin.
        self::assertCount(2, $errors);

        $reported = [];
        $tips = [];
        foreach ($errors as $error) {
            self::assertSame('semitexa.factoryContract', $error->getIdentifier());
            $reported[basename($error->getFilePath()) . ':' . $error->getLine()] = $error->getMessage();
            $tips[basename($error->getFilePath()) . ':' . $error->getLine()] = $error->getTip();
        }
        ksort($reported);
        ksort($tips);
        // A missing or mis-keyed get() is not the narrowing fatal: its own reason (review of core#171).
        $get = (new \ReflectionClass(FactoryContractRule::class))->getConstant('GET_RATIONALE');
        self::assertSame(['FactoryStringKeyed.php:7' => $get, 'FactoryWithoutGet.php:7' => $get], $tips);

        self::assertSame(['FactoryStringKeyed.php:7', 'FactoryWithoutGet.php:7'], array_keys($reported), 'FactoryFixtureChannel must pass');
        self::assertStringContainsString('FactoryWithoutGet must declare get()', $reported['FactoryWithoutGet.php:7']);
        self::assertStringContainsString('FactoryStringKeyed::get() parameter must be a backed enum', $reported['FactoryStringKeyed.php:7']);
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
