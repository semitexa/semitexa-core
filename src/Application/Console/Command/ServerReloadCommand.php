<?php

declare(strict_types=1);

namespace Semitexa\Core\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Runtime\ClearCacheAction;
use Semitexa\Core\Console\Runtime\ReloadRuntimeAction;
use Semitexa\Core\Console\Runtime\VerifyRuntimeAction;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lifecycle: clear cache → reload → verify
 *
 * Does NOT rebuild autoload. Workers are forked from the master, which since
 * tk-master-loads-no-app-classes loads no payload, handler or resource: each worker
 * builds its own container, so a reload applies a change to application code and its
 * attributes as well as templates and caches (measured 13.11: a route path changed in
 * a payload attribute answered after a reload). What the master itself loaded, the
 * framework's bootstrap and the lifecycle listeners, a reload cannot change: it
 * refuses, naming the file, and server:restart app applies it (MasterLoadedFiles).
 */
#[AsCommand(name: 'server:reload', description: 'Gracefully reload Swoole workers: application code and attributes, templates and caches; refuses when a file the master loaded changed')]
class ServerReloadCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('server:reload')
            ->setDescription('Gracefully reload Swoole workers: application code and attributes, templates and caches; refuses when a file the master loaded changed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Reloading Swoole Workers');

        // 1. Clear cache (twig, etc.)
        $clearCache = new ClearCacheAction($io);
        try {
            $clearCache->execute();
        } catch (\Throwable $e) {
            $io->warning(sprintf(
                'Cache clearing failed: %s. Continuing with reload and verification.',
                $e->getMessage()
            ));
        }

        // 2. Reload: send SIGUSR1
        $reload = new ReloadRuntimeAction($io);
        if (!$reload->execute()) {
            return Command::FAILURE;
        }

        // 3. Verify: health check only (no build marker check)
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
