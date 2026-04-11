<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'queue:work', description: 'Run async events worker (processes handlers enqueued with execution: async)')]
class QueueWorkCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('queue:work')
            ->setDescription('Run async events worker (processes handlers enqueued with execution: async)')
            ->addArgument('transport', InputArgument::OPTIONAL, 'Transport: nats or in-memory (default from EVENTS_ASYNC)', null)
            ->addArgument('queue', InputArgument::OPTIONAL, 'Queue name (default from EVENTS_QUEUE_DEFAULT)', null)
            ->addOption('timeout', 't', InputOption::VALUE_OPTIONAL, 'Worker timeout in seconds', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $transport = $input->getArgument('transport');
        $queue = $input->getArgument('queue');

        $io->title('Events worker (queue)');
        
        try {
            $worker = new \Semitexa\Core\Queue\QueueWorker();
            $worker->setOutput($output);
            $worker->run($transport, $queue);
        } catch (\Throwable $e) {
            $io->error('Worker failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}

