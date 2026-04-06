<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Runtime\ReloadRuntimeAction;
use Semitexa\Core\Console\Runtime\VerifyRuntimeAction;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lifecycle: reload → verify
 *
 * Does NOT rebuild autoload. Only safe for changes inside existing files.
 * For new/renamed/deleted classes, use server:restart.
 */
#[AsCommand(name: 'server:reload', description: 'Gracefully reload Swoole workers (fast, no autoload rebuild)')]
class ServerReloadCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('server:reload')
            ->setDescription('Gracefully reload Swoole workers (fast, no autoload rebuild)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Reloading Swoole Workers');

        // 1. Reload: send SIGUSR1
        $reload = new ReloadRuntimeAction($io);
        if (!$reload->execute()) {
            return Command::FAILURE;
        }

        // 2. Verify: health check only (no build marker check)
        // Give workers a moment to cycle
        sleep(2);

        $verify = new VerifyRuntimeAction($io);
        if (!$verify->execute(checkBuildMarker: false)) {
            return Command::FAILURE;
        }

        $io->success('Swoole workers reloaded successfully!');
        return Command::SUCCESS;
    }
}
