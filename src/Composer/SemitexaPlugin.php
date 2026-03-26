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
 * - For semitexa/ultimate projects, keep the project scaffold in sync through the
 *   `semitexa init` command provided by semitexa/ultimate.
 * - For non-ultimate projects, skip scaffold sync because the canonical assets
 *   now live in semitexa/ultimate.
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
        // no-op (required by PluginInterface)
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // no-op (required by PluginInterface)
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

        $ultimate = $repo->findPackage('semitexa/ultimate', '*');
        $rootPackage = $this->composer->getPackage();
        $isUltimateRoot = $rootPackage->getName() === 'semitexa/ultimate';
        if ($ultimate === null && !$isUltimateRoot) {
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
            $this->runRegistrySync($php, $bin, $root);
            $this->io->write('<info>Semitexa: project structure created. Next: cp .env.example .env && bin/semitexa server:start</info>');
            return;
        }

        // Existing project: refresh docs + scaffold (Dockerfile, docker-compose, phpunit, bin/semitexa, public/.htaccess, etc.); never touch .env
        $this->io->write('<info>Semitexa: syncing docs and scaffold (semitexa init --only-docs)...</info>');
        $process = new Process([$php, $bin, 'init', '--only-docs', '--force'], $root);
        $process->setTimeout(30);
        $process->run(function (string $type, string $buffer): void {
            $this->io->write($buffer, false);
        });
        if ($process->isSuccessful()) {
            $this->runRegistrySync($php, $bin, $root);
            $this->io->write('<info>Semitexa: docs and scaffold (Dockerfile, docker-compose, phpunit, etc.) updated. .env not touched. Your notes: AI_NOTES.md (never overwritten).</info>');
        }
    }

    private function runRegistrySync(string $php, string $bin, string $root): void
    {
        $this->io->write('<info>Semitexa: syncing registry (payloads + contracts)...</info>');
        $process = new Process([$php, $bin, 'registry:sync'], $root);
        $process->setTimeout(60);
        $process->run(function (string $type, string $buffer): void {
            $this->io->write($buffer, false);
        });
        if (!$process->isSuccessful()) {
            $this->io->write('<comment>Semitexa registry:sync failed. Run manually: bin/semitexa registry:sync</comment>');
        }
    }
}
