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
 * Does NOT rebuild autoload or recreate containers. Workers are forked from the
 * master, which loads the discovered classes (payloads, handlers, resources) before
 * it starts them, so a reload applies templates and caches, not a change to that
 * code or its attributes: for those, and for new classes or .env changes,
 * server:restart app. It refuses when a file the master loaded has changed
 * (MasterLoadedFiles, tk-server-reload-keeps-route-access).
 */
#[AsCommand(name: 'server:reload', description: 'Gracefully reload Swoole workers: templates and caches; a code or attribute change needs server:restart app')]
class ServerReloadCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('server:reload')
            ->setDescription('Gracefully reload Swoole workers: templates and caches; a code or attribute change needs server:restart app');
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
