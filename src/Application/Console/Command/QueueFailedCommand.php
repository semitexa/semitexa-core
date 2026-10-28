<?php

declare(strict_types=1);

namespace Semitexa\Core\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Queue\QueueInspection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** What the queues gave up on, and how much is waiting: per queue, newest failures first. */
#[AsCommand(name: 'queue:failed', description: 'List dead letters and queue depth; retry with queue:retry.')]
final class QueueFailedCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected QueueInspection $inspection;

    protected function configure(): void
    {
        $this->setName('queue:failed')
            ->setDescription('List dead letters and queue depth; retry with queue:retry.')
            ->addOption('transport', null, InputOption::VALUE_REQUIRED, 'Only this transport')
            ->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Only this queue')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Dead letters per queue', '20')
            ->addOption('payload', null, InputOption::VALUE_NONE, 'Print each payload (it may hold request data)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int) $input->getOption('limit'));
        $withPayload = (bool) $input->getOption('payload');
        $report = [];
        $unsupported = 0;

        foreach ($this->inspection->targets(self::option($input, 'transport'), self::option($input, 'queue')) as $target) {
            $inspector = $target['inspector'];
            if ($inspector === null) {
                $unsupported++;
                $report[] = ['transport' => $target['transport'], 'queue' => $target['queue'], 'supported' => false];
                continue;
            }
            $report[] = [
                'transport' => $target['transport'],
                'queue' => $target['queue'],
                'supported' => true,
                'waiting' => $inspector->depth($target['queue']),
                'oldest_waiting_seconds' => $inspector->oldestAgeSeconds($target['queue']),
                'dead_letters' => array_map(static fn ($l): array => [
                    'id' => $l->id,
                    'target' => $l->target,
                    'attempts' => $l->attempts,
                    'error' => $l->error,
                    'failed_at' => $l->failedAt?->format(DATE_ATOM),
                    'retryable' => $l->retryable,
                ] + ($withPayload ? ['payload' => $l->payload] : []), $inspector->deadLetters($target['queue'], $limit)),
            ];
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

            return $unsupported === count($report) && $report !== [] ? Command::FAILURE : Command::SUCCESS;
        }

        foreach ($report as $entry) {
            $where = "{$entry['transport']} / {$entry['queue']}";
            if (!$entry['supported']) {
                $output->writeln("<comment>{$where}: this transport cannot be inspected.</comment>");
                continue;
            }
            $output->writeln(sprintf(
                '<info>%s</info>: %d waiting%s, %d dead letter(s) shown',
                $where,
                $entry['waiting'],
                $entry['oldest_waiting_seconds'] !== null ? ", oldest {$entry['oldest_waiting_seconds']} s" : '',
                count($entry['dead_letters']),
            ));
            if ($entry['dead_letters'] === []) {
                continue;
            }
            (new Table($output))
                ->setHeaders(['id', 'for', 'attempts', 'failed at', 'error'])
                ->setRows(array_map(static fn (array $l): array => [
                    $l['id'] . ($l['retryable'] ? '' : ' (unreadable)'),
                    $l['target'],
                    $l['attempts'],
                    $l['failed_at'] ?? '',
                    mb_strimwidth($l['error'], 0, 80, '…'),
                ], $entry['dead_letters']))
                ->render();
            if ($withPayload) {
                foreach ($entry['dead_letters'] as $l) {
                    $output->writeln("{$l['id']}: " . ($l['payload'] ?? ''), OutputInterface::OUTPUT_RAW);
                }
            }
        }

        return $unsupported === count($report) && $report !== [] ? Command::FAILURE : Command::SUCCESS;
    }

    private static function option(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
