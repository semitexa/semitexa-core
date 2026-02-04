<?php

declare(strict_types=1);

namespace Semitexa\Core\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Symfony\Component\Process\Process;

/**
 * Composer plugin: after install/update, if semitexa/core is installed:
 * - New project (missing server.php, AI_ENTRY.md, README.md, or docker-compose.yml): runs full "semitexa init".
 * - Existing project: runs "semitexa init --only-docs --force" so AI_ENTRY, README, docs/, server.php and .env.example stay up to date. .env is never touched. Use AI_NOTES.md for your own notes — it is never overwritten.
 */
final class SemitexaPlugin implements PluginInterface, EventSubscriberInterface
{
    private Composer $composer;
    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => ['onPostInstallOrUpdate', 0],
            ScriptEvents::POST_UPDATE_CMD => ['onPostInstallOrUpdate', 0],
        ];
    }

    public function onPostInstallOrUpdate(Event $event): void
    {
        $repo = $this->composer->getRepositoryManager()->getLocalRepository();
        $package = $repo->findPackage('semitexa/core', '*');
        if ($package === null) {
            return;
        }

        $config = $this->composer->getConfig();
        $vendorDir = $config->get('vendor-dir');
        $root = \dirname($vendorDir);

        $bin = $vendorDir . '/bin/semitexa';
        if (!file_exists($bin)) {
            return;
        }

        $php = PHP_BINARY;
        $needsFullInit = !file_exists($root . '/server.php')
            || !file_exists($root . '/AI_ENTRY.md')
            || !file_exists($root . '/README.md')
            || !file_exists($root . '/docker-compose.yml');

        if ($needsFullInit) {
            $this->io->write('<info>Semitexa: scaffolding / updating project structure (semitexa init)...</info>');
            $process = new Process([$php, $bin, 'init'], $root);
            $process->setTimeout(30);
            $process->run(function (string $type, string $buffer): void {
                $this->io->write($buffer, false);
            });
            if (!$process->isSuccessful()) {
                $this->io->write('<comment>Semitexa init failed. Run manually: vendor/bin/semitexa init</comment>');
                return;
            }
            $this->io->write('<info>Semitexa: project structure created. Next: cp .env.example .env && bin/semitexa server:start</info>');
            return;
        }

        // Existing project: refresh docs + server.php + .env.example (never touch .env)
        $this->io->write('<info>Semitexa: refreshing project docs and scaffold (semitexa init --only-docs)...</info>');
        $process = new Process([$php, $bin, 'init', '--only-docs', '--force'], $root);
        $process->setTimeout(30);
        $process->run(function (string $type, string $buffer): void {
            $this->io->write($buffer, false);
        });
        if ($process->isSuccessful()) {
            $this->io->write('<info>Semitexa: docs, server.php and .env.example updated. .env not touched. Your notes: AI_NOTES.md (never overwritten).</info>');
        }
    }
}
