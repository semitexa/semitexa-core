<?php

declare(strict_types=1);

namespace Semitexa\Core\Application\Console\Command;

use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Container\ServiceContractRegistry;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * List service contracts and which implementation is active per interface.
 * Helps developers and AI agents see contract → implementation binding and debug DI.
 */
#[AsCommand(name: 'contracts:list', description: 'List service contracts (interfaces) and their active implementation. Use when debugging which class is bound to an interface.')]
#[AsAiSkill(
    allowed: true,
    // Acts for the whole install, not one tenant: an operator's tool. It was
    // hidden from tenants only because core is not a module (30.10).
    operatorOnly: true,
    summary: 'List registered service contracts and their active implementations.',
    useWhen: 'User asks to inspect DI bindings, debug interface resolution, or see which class implements an interface.',
    avoidWhen: 'User asks to modify contracts or change DI configuration.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    supportsDryRun: false,
    argumentPolicy: 'allowlisted',
    exposeArguments: ['json'],
)]
class ContractsListCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    #[InjectAsReadonly]
    protected ModuleRegistry $moduleRegistry;

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

        $registry = new ServiceContractRegistry($this->classDiscovery, $this->moduleRegistry);
        $details = $registry->getContractDetails();
        $decisions = $registry->getDecisions();

        if ($details === []) {
            if ($json) {
                $output->writeln('{"contracts":[]}');
            } else {
                $io->text('No service contracts registered. Add #[SatisfiesServiceContract(of: Interface::class)] on implementation classes in modules.');
            }
            return Command::SUCCESS;
        }

        if ($json) {
            $this->outputJson($output, $details, $decisions);
            return Command::SUCCESS;
        }

        $this->outputTable($io, $details, $decisions);
        return Command::SUCCESS;
    }

    /**
     * Human-readable table: Contract (interface) | Implementations | Active | Decided by
     * @param array<string, array{implementations: list<array{module: string, class: string}>, active: string}> $details
     * @param array<string, array{decided_by: string, tied_with: list<array{module: string, class: string}>}> $decisions
     */
    private function outputTable(SymfonyStyle $io, array $details, array $decisions): void
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

            $decidedBy = '';
            if (isset($decisions[$interface])) {
                $decidedBy = $decisions[$interface]['decided_by'];
                foreach ($decisions[$interface]['tied_with'] as $tie) {
                    $decidedBy .= "\ntied with " . $tie['module'] . ' → ' . $this->shortClass($tie['class']);
                }
            }

            $rows[] = [
                $this->shortClass($interface),
                implode("\n", $implList),
                $this->shortClass($active),
                $decidedBy,
            ];
        }

        $io->title('Service contracts (interface → implementations, active marked)');
        $io->table(
            ['Contract (interface)', 'Implementations (module → class)', 'Active', 'Decided by'],
            $rows
        );
        $io->text('Resolution: a module that extends another (composer.json extra.semitexa-module.extends) wins over it, a project module wins over a package, any module wins over Core. Between modules nothing relates, the one found first wins: "discovery-order", which lint:di warns about between two project modules. Add #[SatisfiesServiceContract(of: Interface::class)] on implementation classes.');
    }

    /**
     * JSON output for AI agents and scripts: stable structure, easy to parse.
     * decided_by is null for a contract with one implementation.
     * @param array<string, array{implementations: list<array{module: string, class: string}>, active: string}> $details
     * @param array<string, array{decided_by: string, tied_with: list<array{module: string, class: string}>}> $decisions
     */
    private function outputJson(OutputInterface $output, array $details, array $decisions): void
    {
        $out = [];
        foreach ($details as $interface => $data) {
            $out[] = [
                'contract' => $interface,
                'active' => $data['active'],
                'implementations' => $data['implementations'],
                'decided_by' => $decisions[$interface]['decided_by'] ?? null,
                'tied_with' => $decisions[$interface]['tied_with'] ?? [],
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
