<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

use Semitexa\Core\Container\ServiceContractRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * List service contracts and which implementation is active per interface.
 * Helps developers and AI agents see contract → implementation binding and debug DI.
 */
class ContractsListCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('contracts:list')
            ->setDescription('List service contracts (interfaces) and their active implementation. Use when debugging which class is bound to an interface.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON (for AI agents and scripting)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = (bool) $input->getOption('json');

        $registry = new ServiceContractRegistry();
        $details = $registry->getContractDetails();

        if ($details === []) {
            if ($json) {
                $output->writeln('{"contracts":[]}');
            } else {
                $io->text('No service contracts registered. Add #[AsServiceContract(of: Interface::class)] on implementation classes in modules.');
            }
            return Command::SUCCESS;
        }

        if ($json) {
            $this->outputJson($output, $details);
            return Command::SUCCESS;
        }

        $this->outputTable($io, $details);
        return Command::SUCCESS;
    }

    /**
     * Human-readable table: Contract (interface) | Implementations | Active
     * @param array<string, array{implementations: list<array{module: string, class: string}>, active: string}> $details
     */
    private function outputTable(SymfonyStyle $io, array $details): void
    {
        $rows = [];
        foreach ($details as $interface => $data) {
            $implementations = $data['implementations'];
            $active = $data['active'];

            $implList = [];
            foreach ($implementations as $item) {
                $shortClass = $this->shortClass($item['class']);
                $mark = $item['class'] === $active ? ' ✓' : '';
                $implList[] = $item['module'] . ' → ' . $shortClass . $mark;
            }

            $rows[] = [
                $this->shortClass($interface),
                implode("\n", $implList),
                $this->shortClass($active),
            ];
        }

        $io->title('Service contracts (interface → implementations, active marked)');
        $io->table(
            ['Contract (interface)', 'Implementations (module → class)', 'Active'],
            $rows
        );
        $io->text('Resolution: module "extends" order (child module wins). Add #[AsServiceContract(of: Interface::class)] on implementation classes.');
    }

    /**
     * JSON output for AI agents and scripts: stable structure, easy to parse.
     * @param array<string, array{implementations: list<array{module: string, class: string}>, active: string}> $details
     */
    private function outputJson(OutputInterface $output, array $details): void
    {
        $out = [];
        foreach ($details as $interface => $data) {
            $out[] = [
                'contract' => $interface,
                'active' => $data['active'],
                'implementations' => $data['implementations'],
            ];
        }
        $json = json_encode(['contracts' => $out], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $output->writeln($json !== false ? $json : '{}');
    }

    private function shortClass(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts);
    }
}
