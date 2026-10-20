<?php

declare(strict_types=1);

namespace Semitexa\Core\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Event\EventListenerRegistry;
use Semitexa\Core\Queue\HandlerExecution;
use Semitexa\Core\Queue\QueueConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What a project's queue workers must consume. queue:work takes one transport
 * and one queue; a listener or handler may name its own, so one worker on the
 * default queue leaves the others unread. The scaffold's worker service runs
 * one queue:work per line this prints: "<transport> <queue>".
 */
#[AsCommand(name: 'queue:queues', description: 'List every transport and queue the queue workers must consume')]
class QueueQueuesCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected AttributeDiscovery $attributeDiscovery;

    #[InjectAsReadonly]
    protected EventListenerRegistry $eventListenerRegistry;

    protected function configure(): void
    {
        $this->setName('queue:queues')
            ->setDescription('List every transport and queue the queue workers must consume')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON, with what declares each queue');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $defaultTransport = QueueConfig::defaultTransport();
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        // The default queue is always consumed, so a worker runs before anything is queued.
        $queues = [];
        $add = static function (?string $transport, string $queue, ?string $declaredBy) use (&$queues, $defaultTransport): void {
            $key = ($transport ?: $defaultTransport) . ' ' . $queue;
            $queues[$key] ??= ['transport' => $transport ?: $defaultTransport, 'queue' => $queue, 'declared_by' => []];
            if ($declaredBy !== null) {
                $queues[$key]['declared_by'][] = $declaredBy;
            }
        };
        $add(null, QueueConfig::defaultQueueName('default'), null);

        foreach ($this->eventListenerRegistry->queuedListeners() as $meta) {
            $add(self::name($meta['transport']), self::name($meta['queue']) ?? QueueConfig::defaultQueueName($meta['event']), $meta['class']);
        }

        $this->attributeDiscovery->initialize();
        $handlers = $this->attributeDiscovery->getHandlerRegistry();
        foreach ($handlers->getHandlerClassNames() as $class) {
            $meta = $handlers->getHandlerByClass($class);
            if ($meta === null || $meta['execution'] !== HandlerExecution::Async->value) {
                continue;
            }
            $add(self::name($meta['transport']), self::name($meta['queue']) ?? QueueConfig::defaultQueueName($meta['payload'] ?? $class), $class);
        }

        ksort($queues);
        foreach ($queues as &$entry) {
            sort($entry['declared_by']);
        }
        unset($entry);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(array_values($queues), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
        }

        foreach ($queues as $entry) {
            $output->writeln($entry['transport'] . ' ' . $entry['queue'], OutputInterface::OUTPUT_RAW);
        }

        return Command::SUCCESS;
    }

    private static function name(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
