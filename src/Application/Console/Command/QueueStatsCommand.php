<?php

declare(strict_types=1);

namespace Semitexa\Core\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Queue\QueueInspection;
use Semitexa\Core\Queue\QueueStatsStores;
use Semitexa\Core\Queue\QueueStatsWindow;
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
        $window = self::minutes((string) $input->getOption('window'));
        if ($window === null) {
            $output->writeln('<error>--window is minutes (15) or hours (2h).</error>');

            return Command::FAILURE;
        }
        $store = QueueStatsStores::fromEnvironment();
        $now = intdiv(time(), 60);

        $rows = [];
        foreach ($this->inspection->targets(self::option($input, 'transport'), self::option($input, 'queue')) as $target) {
            $stats = new QueueStatsWindow($store?->sum($target['queue'], $now - $window + 1, $now) ?? [], $window);
            $inspector = $target['inspector'];
            $rows[] = [
                'transport' => $target['transport'],
                'queue' => $target['queue'],
                'processed' => $stats->count('processed'),
                'retried' => $stats->count('retried'),
                'dead_lettered' => $stats->count('dead_lettered'),
                'per_minute' => round($stats->perMinute(), 2),
                'wait_ms' => ['avg' => $stats->averageMs('wait'), 'p50' => $stats->percentileMs('wait', 0.5), 'p95' => $stats->percentileMs('wait', 0.95)],
                'run_ms' => ['avg' => $stats->averageMs('run'), 'p50' => $stats->percentileMs('run', 0.5), 'p95' => $stats->percentileMs('run', 0.95)],
                'waiting' => $inspector?->depth($target['queue']),
                'oldest_waiting_seconds' => $inspector?->oldestAgeSeconds($target['queue']),
            ];
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode([
                'window_minutes' => $window,
                'store' => $store?->describe() ?? 'off',
                'queues' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Last %d min, counters in %s. Percentiles are bucket bounds (p95 ≤ x).', $window, $store?->describe() ?? 'nothing (QUEUE_STATS_STORE=off)'));
        (new Table($output))
            ->setHeaders(['queue', 'done', 'retried', 'dead', '/min', 'wait avg · p95', 'run avg · p95', 'waiting', 'oldest'])
            ->setRows(array_map(static fn (array $r): array => [
                "{$r['transport']}/{$r['queue']}",
                $r['processed'],
                $r['retried'],
                $r['dead_lettered'],
                $r['per_minute'],
                self::ms($r['wait_ms']['avg']) . ' · ' . self::ms($r['wait_ms']['p95']),
                self::ms($r['run_ms']['avg']) . ' · ' . self::ms($r['run_ms']['p95']),
                $r['waiting'] ?? '?',
                $r['oldest_waiting_seconds'] !== null ? $r['oldest_waiting_seconds'] . ' s' : '',
            ], $rows))
            ->render();

        return Command::SUCCESS;
    }

    private static function minutes(string $value): ?int
    {
        if (preg_match('/^(\d{1,5})(h|m)?$/', trim($value), $m) !== 1 || (int) $m[1] < 1) {
            return null;
        }

        return min(7 * 1440, ($m[2] ?? 'm') === 'h' ? (int) $m[1] * 60 : (int) $m[1]);
    }

    private static function ms(int|string|null $value): string
    {
        return match (true) {
            $value === null => '—',
            $value === 'inf' => '>30 s',
            default => $value . ' ms',
        };
    }

    private static function option(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
