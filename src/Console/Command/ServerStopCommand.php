<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Runtime\StopRuntimeAction;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'server:stop', description: 'Stop Semitexa Environment')]
class ServerStopCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('server:stop')
            ->setDescription('Stop Semitexa Environment');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Stopping Semitexa');

        $stop = new StopRuntimeAction($io);
        if (!$stop->execute()) {
            return Command::FAILURE;
        }

        $io->success('Stopped.');
        return Command::SUCCESS;
    }
}
