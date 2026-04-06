<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Runtime\PrepareRuntimeAction;
use Semitexa\Core\Console\Runtime\StartRuntimeAction;
use Semitexa\Core\Console\Runtime\StopRuntimeAction;
use Semitexa\Core\Console\Runtime\VerifyRuntimeAction;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lifecycle: prepare → stop → start → verify
 *
 * This is the command that refreshes code, autoload, env, and container state.
 */
#[AsCommand(name: 'server:restart', description: 'Restart Semitexa Environment (full restart with autoload rebuild and env refresh)')]
class ServerRestartCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('server:restart')
            ->setDescription('Restart Semitexa Environment (full restart with autoload rebuild and env refresh)')
            ->addOption('service', 's', InputOption::VALUE_REQUIRED, 'Specific service to restart');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string|null $service */
        $service = $input->getOption('service');
        if ($service !== null && trim($service) === '') {
            $io->error('The --service option requires a non-empty service name.');
            return Command::FAILURE;
        }

        $io->title('Restarting Semitexa Environment');

        // 1. Prepare: composer dump-autoload + build marker
        $prepare = new PrepareRuntimeAction($io);
        if (!$prepare->execute()) {
            return Command::FAILURE;
        }

        // 2. Stop
        $stop = new StopRuntimeAction($io);
        if (!$stop->execute($service)) {
            $io->error('Stop phase failed; aborting restart to avoid starting into a dirty runtime.');
            return Command::FAILURE;
        }

        // 3. Start
        $start = new StartRuntimeAction($io);
        if (!$start->execute($service)) {
            return Command::FAILURE;
        }

        // 4. Verify
        $verify = new VerifyRuntimeAction($io);
        if (!$verify->execute(checkBuildMarker: true)) {
            return Command::FAILURE;
        }

        $io->success('Semitexa environment restarted successfully!');
        return Command::SUCCESS;
    }
}
