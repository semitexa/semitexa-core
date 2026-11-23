<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Application\Console\Command\ContractsListCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * contracts:list tells the reader which attribute to add. For months it named
 * #[AsServiceContract], which does not exist: whoever followed the hint got an
 * unknown-attribute class and no binding.
 */
final class ContractsListCommandTest extends TestCase
{
    #[Test]
    public function the_empty_hint_names_only_attributes_that_exist(): void
    {
        $display = $this->display([]);

        self::assertStringContainsString('No service contracts registered', $display);
        $this->assertEveryAttributeExists($display);
    }

    #[Test]
    public function the_table_footer_names_only_attributes_that_exist(): void
    {
        $display = $this->display([ContractsListFixtureImplementation::class]);

        self::assertStringContainsString('ContractsListFixtureContract', $display, 'the table was not printed');
        self::assertStringContainsString('ContractsListFixtureImplementation', $display);
        $this->assertEveryAttributeExists($display);
    }

    private function assertEveryAttributeExists(string $display): void
    {
        preg_match_all('/#\[([A-Za-z_\\\\]+)/', $display, $matches);
        self::assertNotSame([], $matches[1], 'the hint names no attribute at all: ' . $display);

        foreach (array_unique($matches[1]) as $name) {
            self::assertTrue(
                class_exists('Semitexa\\Core\\Attribute\\' . $name),
                "contracts:list prints #[{$name}], and Semitexa\\Core\\Attribute\\{$name} does not exist",
            );
        }
    }

    /** @param list<class-string> $implementations */
    private function display(array $implementations): string
    {
        $command = new ContractsListCommand();
        (new \ReflectionProperty($command, 'classDiscovery'))->setValue($command, new ContractsListFixtureDiscovery($implementations));
        (new \ReflectionProperty($command, 'moduleRegistry'))->setValue($command, new RoutesFixtureModules());
        $tester = new CommandTester($command);
        $tester->execute([], ['decorated' => false]);

        // SymfonyStyle wraps long lines; an attribute name must survive that.
        return (string) preg_replace('/\s*\n\s*/', ' ', $tester->getDisplay());
    }
}
