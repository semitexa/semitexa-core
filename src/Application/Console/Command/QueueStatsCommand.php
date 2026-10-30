<?php

declare(strict_types=1);

namespace Semitexa\Core\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Queue\QueueInspection;
use Semitexa\Core\Queue\QueueStatsReport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Per queue, over the last minutes: jobs done, retried and dead-lettered, the
 * rate, how long jobs waited and ran (average and estimated p50/p95), and
 * what is waiting now.
 */
#[AsCommand(name: 'queue:stats', description: 'Throughput, failures, wait and run time per queue, and what is waiting now.')]
final class QueueStatsCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected QueueInspection $inspection;

    protected function configure(): void
    {
        $this->setName('queue:stats')
            ->setDescription('Throughput, failures, wait and run time per queue, and what is waiting now.')
            ->addOption('transport', null, InputOption::VALUE_REQUIRED, 'Only this transport')
            ->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Only this queue')
            ->addOption('window', null, InputOption::VALUE_REQUIRED, 'How far back, in minutes (or 2h)', '15')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $window = self::minutes(self::optionText($input->getOption('window')));
        if ($window === null) {
            $output->writeln('<error>--window is minutes (15) or hours (2h).</error>');

            return Command::FAILURE;
        }
        $report = QueueStatsReport::build($this->inspection, $window, self::option($input, 'transport'), self::option($input, 'queue'));
        $rows = $report['queues'];
        // A queue or its counters that could not be read fail the command in
        // both forms: a monitor reading --json is told by the exit code too.
        $status = array_filter($rows, static fn (array $r): bool => $r['error'] !== null || $r['counters_error'] !== null) === []
            ? Command::SUCCESS
            : Command::FAILURE;

        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode([
                'window_minutes' => $window,
                'store' => $report['store'],
                'queues' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return $status;
        }

        $output->writeln(sprintf('Last %d min, counters in %s. Percentiles are bucket bounds (p95 ≤ x).', $window, $report['store'] !== 'off' ? $report['store'] : 'nothing (QUEUE_STATS_STORE=off)'));
        (new Table($output))
            ->setHeaders(['queue', 'done', 'retried', 'dead', '/min', 'wait avg · p95', 'run avg · p95', 'waiting', 'oldest'])
            ->setRows(array_map(static fn (array $r): array => [
                "{$r['transport']}/{$r['queue']}",
                $r['processed'] ?? '?',
                $r['retried'] ?? '?',
                $r['dead_lettered'] ?? '?',
                $r['per_minute'] ?? '?',
                QueueStatsReport::ms($r['wait_ms']['avg']) . ' · ' . QueueStatsReport::ms($r['wait_ms']['p95']),
                QueueStatsReport::ms($r['run_ms']['avg']) . ' · ' . QueueStatsReport::ms($r['run_ms']['p95']),
                $r['waiting'] ?? '?',
                $r['oldest_waiting_seconds'] !== null ? $r['oldest_waiting_seconds'] . ' s' : '',
            ], $rows))
            ->render();

        // What could not be read is a "?" in the table: say why.
        foreach ($rows as $r) {
            if ($r['error'] !== null) {
                $output->writeln("<error>{$r['transport']}/{$r['queue']}: {$r['error']}</error>");
            }
            if ($r['counters_error'] !== null) {
                $output->writeln("<error>{$r['transport']}/{$r['queue']} counters ({$report['store']}): {$r['counters_error']}</error>");
            }
        }

        return $status;
    }

    private static function minutes(string $value): ?int
    {
        if (preg_match('/^(\d{1,5})(h|m)?$/', trim($value), $m) !== 1 || (int) $m[1] < 1) {
            return null;
        }

        return min(7 * 1440, ($m[2] ?? 'm') === 'h' ? (int) $m[1] * 60 : (int) $m[1]);
    }

    private static function option(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
