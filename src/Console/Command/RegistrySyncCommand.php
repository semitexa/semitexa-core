<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

use Semitexa\Core\Attributes\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Convenience command: runs registry:sync:contracts and (if available) registry:sync:entities.
 * Payload and resource sync have been removed — discovery happens at runtime.
 */
#[AsCommand(name: 'registry:sync', description: 'Run all registry sync commands (contracts, and entities if available).')]
class RegistrySyncCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('registry:sync')
            ->setDescription('Run all registry sync commands (contracts, and entities if available).')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Pass --json to sub-commands that support it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = $this->getApplication();
        if ($app === null) {
            $output->writeln('<error>Application not available.</error>');
            return Command::FAILURE;
        }

        $io = new SymfonyStyle($input, $output);
        $json = (bool) $input->getOption('json');
        $exitCode = Command::SUCCESS;

        $contractsCmd = $app->find('registry:sync:contracts');
        if ($contractsCmd->run(new ArrayInput([]), $output) !== Command::SUCCESS) {
            $exitCode = Command::FAILURE;
        }

        if ($app->has('registry:sync:entities')) {
            $entitiesCmd = $app->find('registry:sync:entities');
            $entitiesInput = new ArrayInput($json ? ['--json' => true] : []);
            if ($entitiesCmd->run($entitiesInput, $output) !== Command::SUCCESS) {
                $exitCode = Command::FAILURE;
            }
        }

        if ($exitCode === Command::SUCCESS && !$json) {
            $io->success('Registry sync completed.');
        }

        return $exitCode;
    }
}
