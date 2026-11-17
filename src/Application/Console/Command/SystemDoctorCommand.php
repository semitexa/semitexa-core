<?php

declare(strict_types=1);

namespace Semitexa\Core\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\AsDoctorCheck;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Contract\DoctorCheckInterface;
use Semitexa\Core\Support\DoctorResult;
use Semitexa\Core\Support\DoctorStatus;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'system:doctor', description: 'Run environment capability checks declared by installed packages')]
final class SystemDoctorCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    protected function configure(): void
    {
        $this
            ->setName('system:doctor')
            ->setDescription('Run environment capability checks declared by installed packages')
            ->addOption(
                name:        'json',
                mode:        InputOption::VALUE_NONE,
                description: 'Emit a machine-readable JSON report',
            )
            ->addOption(
                name:        'compact',
                mode:        InputOption::VALUE_NONE,
                description: 'Only the checks that are not OK, one line each, and the counts (the default when stdout is not a terminal)',
            )
            ->addOption(
                name:        'table',
                mode:        InputOption::VALUE_NONE,
                description: 'The full table of every check, whatever stdout is',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->classDiscovery->initialize();
        $classes = $this->classDiscovery->findClassesWithAttribute(AsDoctorCheck::class);
        sort($classes);

        $report = [];
        foreach ($classes as $className) {
            // A mis-declared or unloadable check from ANY package must become
            // one failed row, never abort the whole diagnosis.
            $name = $className;
            $package = '';
            try {
                /** @var class-string $className */
                $ref = new \ReflectionClass($className);
                $attrs = $ref->getAttributes(AsDoctorCheck::class);
                if ($attrs === [] || !$ref->implementsInterface(DoctorCheckInterface::class)) {
                    continue;
                }
                /** @var AsDoctorCheck $attr */
                $attr = $attrs[0]->newInstance();
                $name = $attr->name;
                $package = $attr->package;

                /** @var DoctorCheckInterface $check */
                $check = $ref->newInstance();
                $result = $check->run();
            } catch (\Throwable $e) {
                $result = DoctorResult::fail($e::class . ': ' . $e->getMessage());
            }

            $report[] = [
                'name' => $name,
                'package' => $package,
                'status' => $result->status->value,
                'message' => $result->message,
                'hint' => $result->hint,
            ];
        }

        $healthy = !$this->hasFailures($report);

        if ($input->getOption('json')) {
            // Substitute invalid UTF-8 instead of letting a rogue check
            // message turn the report into a JsonException crash.
            $output->writeln((string) json_encode([
                'artifact' => 'semitexa.system-doctor/v1',
                'checks' => $report,
                'healthy' => $healthy,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), OutputInterface::OUTPUT_RAW);

            return $healthy ? Command::SUCCESS : Command::FAILURE;
        }

        // Read by an agent or a log, the table cost about 7,000 characters for 24
        // rows, 20 of them OK, every row padded to the widest message (reported by
        // the Guard project, tk-doctor-table-pads-to-230-columns).
        if ((bool) $input->getOption('compact') || (!$input->getOption('table') && !self::isTerminal($output))) {
            return $this->renderCompact($output, $report, $healthy);
        }

        $io->title('System doctor');

        if ($report === []) {
            $io->warning('No doctor checks discovered — installed packages declare none.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($report as $entry) {
            $rows[] = [
                $this->badge($entry['status']),
                $entry['name'],
                $entry['package'],
                $entry['message'] . ($entry['hint'] !== null ? "\n<comment>fix:</comment> " . $entry['hint'] : ''),
            ];
        }
        $io->table(['Status', 'Check', 'Package', 'Details'], $rows);

        $counts = array_count_values(array_column($report, 'status'));
        $summary = self::summary($report);

        if (!$healthy) {
            $io->error("Environment is NOT healthy: {$summary}");
            return Command::FAILURE;
        }

        ($counts[DoctorStatus::Warn->value] ?? 0) > 0
            ? $io->warning("Environment is usable with warnings: {$summary}")
            : $io->success("Environment is healthy: {$summary}");

        return Command::SUCCESS;
    }

    /**
     * One line of counts, then a line for each check that is not OK (and its fix),
     * then the verdict: nothing padded, nothing an OK check adds. Written raw, so a
     * message carrying markup is printed as it is.
     *
     * @param list<array{name: string, package: string, status: string, message: string, hint: ?string}> $report
     */
    private function renderCompact(OutputInterface $output, array $report, bool $healthy): int
    {
        if ($report === []) {
            $output->writeln('No doctor checks discovered — installed packages declare none.', OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }
        $output->writeln('System doctor: ' . self::summary($report), OutputInterface::OUTPUT_RAW);
        foreach ($report as $entry) {
            if ($entry['status'] === DoctorStatus::Pass->value) {
                continue;
            }
            $output->writeln(sprintf('  %s %s (%s): %s', strtoupper($entry['status']), $entry['name'], $entry['package'], $entry['message']), OutputInterface::OUTPUT_RAW);
            if ($entry['hint'] !== null) {
                $output->writeln('    fix: ' . $entry['hint'], OutputInterface::OUTPUT_RAW);
            }
        }
        $warned = in_array(DoctorStatus::Warn->value, array_column($report, 'status'), true);
        $output->writeln(match (true) {
            !$healthy => 'Environment is NOT healthy.',
            $warned => 'Environment is usable with warnings.',
            default => 'Environment is healthy.',
        }, OutputInterface::OUTPUT_RAW);

        return $healthy ? Command::SUCCESS : Command::FAILURE;
    }

    /** @param list<array{name: string, package: string, status: string, message: string, hint: ?string}> $report */
    private static function summary(array $report): string
    {
        $counts = array_count_values(array_column($report, 'status'));

        return sprintf(
            '%d pass, %d warn, %d fail, %d skip',
            $counts[DoctorStatus::Pass->value] ?? 0,
            $counts[DoctorStatus::Warn->value] ?? 0,
            $counts[DoctorStatus::Fail->value] ?? 0,
            $counts[DoctorStatus::Skip->value] ?? 0,
        );
    }

    /** A person at a terminal reads the table; a pipe, a log or an agent gets the compact form. */
    private static function isTerminal(OutputInterface $output): bool
    {
        return $output instanceof StreamOutput && stream_isatty($output->getStream());
    }

    /**
     * @param list<array{name: string, package: string, status: string, message: string, hint: ?string}> $report
     */
    private function hasFailures(array $report): bool
    {
        return in_array(DoctorStatus::Fail->value, array_column($report, 'status'), true);
    }

    private function badge(string $status): string
    {
        return match ($status) {
            DoctorStatus::Pass->value => '<info> OK </info>',
            DoctorStatus::Warn->value => '<comment>WARN</comment>',
            DoctorStatus::Fail->value => '<error>FAIL</error>',
            default => 'SKIP',
        };
    }
}
