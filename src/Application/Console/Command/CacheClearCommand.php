<?php

declare(strict_types=1);

namespace Semitexa\Core\Application\Console\Command;

use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Semitexa\Core\Console\Runtime\ReloadRuntimeAction;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * Clear application cache (Twig compiled templates, etc.).
 *
 * Removing the compiled templates from disk is only half the job under Swoole:
 * each worker holds its own compiled template in memory for its whole life, so
 * a disk-only clear leaves the workers that had already compiled a template
 * serving the old one. The result is not "stale until restart", which would at
 * least be consistent — it is two versions of the same page being served
 * concurrently, depending on which worker answers. So this command signals the
 * running workers to cycle after clearing, and says plainly when it could not.
 *
 * When cache was created by Swoole (e.g. in Docker as root), run with --via-docker so the command runs inside the container and can delete the files.
 */
#[AsCommand(name: 'cache:clear', description: 'Clear application cache (e.g. var/cache/twig) and cycle running workers so they drop their in-memory templates.')]
#[AsAiSkill(
    allowed: true,
    summary: 'Clear application cache after template changes and cycle running workers.',
    useWhen: 'User asks to clear cache, refresh templates, or fix stale runtime state. Also the right answer when a page shows a mix of old and new output between reloads — that is per-worker compiled templates, and this drops them.',
    avoidWhen: 'User asks to restart services, rebuild containers, or reset Docker.',
    riskLevel: AiRiskLevel::Medium,
    confirmation: AiConfirmationMode::Always,
    supportsDryRun: false,
    argumentPolicy: 'allowlisted',
    exposeArguments: ['twig'],
)]
class CacheClearCommand extends BaseCommand
{
    private const CACHE_DIRS = ['twig'];

    protected function configure(): void
    {
        $this->setName('cache:clear')
            ->setDescription('Clear application cache (e.g. var/cache/twig) and cycle running workers so they drop their in-memory templates.')
            ->addOption('twig', null, InputOption::VALUE_NONE, 'Clear only Twig cache (default: clear all known cache dirs)')
            ->addOption('no-reload', null, InputOption::VALUE_NONE, 'Clear the disk cache only, leaving running workers on their in-memory templates')
            ->addOption('via-docker', null, InputOption::VALUE_NONE, 'Run the clear inside the app container (use when cache was created by Swoole/Docker and host user cannot delete)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $root = $this->getProjectRoot();
        $viaDocker = (bool) $input->getOption('via-docker');

        if ($viaDocker) {
            return $this->runViaDocker($io, $root, $input);
        }

        $baseDir = $root . '/var/cache';
        $twigOnly = (bool) $input->getOption('twig');

        $dirsToClear = $twigOnly ? ['twig'] : self::CACHE_DIRS;
        $cleared = [];
        $failed = [];

        foreach ($dirsToClear as $subDir) {
            $path = $baseDir . '/' . $subDir;
            if (!is_dir($path)) {
                continue;
            }
            if ($this->removeDirectoryContents($path)) {
                $cleared[] = 'var/cache/' . $subDir;
            } else {
                $failed[] = 'var/cache/' . $subDir;
            }
        }

        if (!$twigOnly) {
            $resourceMetadata = $baseDir . '/resource-metadata.php';
            if (is_file($resourceMetadata)) {
                if (@unlink($resourceMetadata)) {
                    $cleared[] = 'var/cache/resource-metadata.php';
                } else {
                    $failed[] = 'var/cache/resource-metadata.php';
                }
            }
        }

        if ($failed !== [] && $this->canRunViaDocker($root)) {
            $io->note('Could not clear from host (permission denied). Running inside Docker container...');
            return $this->runViaDocker($io, $root, $input);
        }

        if ($cleared !== []) {
            $io->success('Cleared: ' . implode(', ', $cleared));
        }
        if ($failed !== []) {
            $io->warning('Could not clear: ' . implode('; ', $failed) . '. Run with --via-docker to clear from inside the app container, or: docker compose exec app bin/semitexa cache:clear');
            return Command::FAILURE;
        }
        if (count($cleared) === 0 && count($failed) === 0) {
            $io->text('Nothing to clear (no cache directories found under var/cache).');
        }

        return $this->dropWorkerTemplates($io, (bool) $input->getOption('no-reload'));
    }

    /**
     * Signal the running workers to cycle, so the templates they compiled into
     * their own memory go with the ones just removed from disk.
     *
     * Clearing the disk alone is what produced the reported symptom: workers
     * that had already compiled a template kept serving it, workers that had
     * not picked up the new one, and the page flipped between the two by
     * whichever worker answered. Nothing in the output said a restart was
     * needed, so the clear looked like it had worked.
     */
    private function dropWorkerTemplates(SymfonyStyle $io, bool $noReload): int
    {
        if ($noReload) {
            $io->note('Disk cache only (--no-reload). Running workers keep the templates they already compiled; use server:reload to drop those too.');

            return Command::SUCCESS;
        }

        $reload = new ReloadRuntimeAction($io);
        $presence = $reload->serverPresence();

        if ($presence === ReloadRuntimeAction::PRESENCE_ABSENT) {
            $io->text('No running server found — nothing holds a compiled template in memory.');

            return Command::SUCCESS;
        }

        if ($presence === ReloadRuntimeAction::PRESENCE_UNKNOWN) {
            // A pidfile exists but could not be read or verified. Saying "no
            // running server" here would be a guess dressed as a fact, and the
            // guess that costs the operator: a live worker keeps serving the
            // template we just deleted while the command reports success.
            $io->warning('A pidfile exists but the Swoole master could not be verified, so the workers were NOT reloaded. Disk cache is clear; run server:reload (or server:restart) before trusting what the page shows.');

            return Command::FAILURE;
        }

        if (!$reload->execute()) {
            $io->warning('Disk cache is cleared, but the running workers still hold their compiled templates. Run server:reload (or server:restart) before trusting what the page shows.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function runViaDocker(SymfonyStyle $io, string $root, InputInterface $input): int
    {
        // Forward the flags, so the in-container run behaves like the one the
        // operator asked for — including whether the workers get cycled. The
        // Swoole master lives in that container, so the reload belongs there.
        $args = ['bin/semitexa', 'cache:clear'];
        if ($input->getOption('twig')) {
            $args[] = '--twig';
        }
        if ($input->getOption('no-reload')) {
            $args[] = '--no-reload';
        }
        $process = new Process(['docker', 'compose', 'exec', '-T', 'app', 'php', ...$args], $root);
        $process->setTimeout(30);
        $process->run(function (string $type, string $buffer) use ($io): void {
            $io->write($buffer);
        });
        if ($process->isSuccessful()) {
            $io->success('Cache cleared (via Docker).');
            return Command::SUCCESS;
        }
        $io->error('Docker exec failed. Is the app container running? Try: docker compose exec app bin/semitexa cache:clear');
        return Command::FAILURE;
    }

    private function canRunViaDocker(string $root): bool
    {
        if (!is_file($root . '/docker-compose.yml')) {
            return false;
        }
        $process = new Process(['docker', 'compose', 'exec', '-T', 'app', 'true'], $root);
        $process->setTimeout(5);
        $process->run();
        return $process->isSuccessful();
    }


    /**
     * Remove all contents of a directory (files and subdirs). Directory itself is kept.
     */
    private function removeDirectoryContents(string $path): bool
    {
        if (!is_dir($path) || !is_readable($path)) {
            return false;
        }
        $ok = true;
        $items = @scandir($path);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $ok = $this->removeDirectoryRecursive($full) && $ok;
            } else {
                $ok = @unlink($full) && $ok;
            }
        }
        return $ok;
    }

    private function removeDirectoryRecursive(string $path): bool
    {
        if (!is_dir($path)) {
            return true;
        }
        $items = @scandir($path);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->removeDirectoryRecursive($full);
            }
            @unlink($full);
        }
        return @rmdir($path);
    }
}
