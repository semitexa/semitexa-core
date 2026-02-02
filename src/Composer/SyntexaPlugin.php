<?php

declare(strict_types=1);

namespace Syntexa\Core\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Symfony\Component\Process\Process;

/**
 * Composer plugin: after install/update, if syntexa/core is installed:
 * - New project (missing server.php, AI_ENTRY.md, README.md, or docker-compose.yml): runs full "syntexa init".
 * - Existing project: runs "syntexa init --force" so all scaffolded files (server.php, docs, docker-compose, etc.) stay up to date. Use AI_NOTES.md for your own notes — it is never overwritten.
 */
final class SyntexaPlugin implements PluginInterface, EventSubscriberInterface
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
        $package = $repo->findPackage('syntexa/core', '*');
        if ($package === null) {
            return;
        }

        $config = $this->composer->getConfig();
        $vendorDir = $config->get('vendor-dir');
        $root = \dirname($vendorDir);

        $bin = $vendorDir . '/bin/syntexa';
        if (!file_exists($bin)) {
            return;
        }

        $php = PHP_BINARY;
        $needsFullInit = !file_exists($root . '/server.php')
            || !file_exists($root . '/AI_ENTRY.md')
            || !file_exists($root . '/README.md')
            || !file_exists($root . '/docker-compose.yml');

        if ($needsFullInit) {
            $this->io->write('<info>Syntexa: scaffolding / updating project structure (syntexa init)...</info>');
            $process = new Process([$php, $bin, 'init'], $root);
            $process->setTimeout(30);
            $process->run(function (string $type, string $buffer): void {
                $this->io->write($buffer, false);
            });
            if (!$process->isSuccessful()) {
                $this->io->write('<comment>Syntexa init failed. Run manually: vendor/bin/syntexa init</comment>');
                return;
            }
            $this->io->write('<info>Syntexa: project structure created. Next: cp .env.example .env && bin/syntexa server:start</info>');
            return;
        }

        // Existing project: refresh all scaffolded files from template (server.php, docs, docker-compose, etc.)
        $this->io->write('<info>Syntexa: refreshing project scaffold (syntexa init --force)...</info>');
        $process = new Process([$php, $bin, 'init', '--force'], $root);
        $process->setTimeout(30);
        $process->run(function (string $type, string $buffer): void {
            $this->io->write($buffer, false);
        });
        if ($process->isSuccessful()) {
            $this->io->write('<info>Syntexa: scaffold updated. Your own notes: AI_NOTES.md (never overwritten).</info>');
        }
    }
}
