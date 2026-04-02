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
 * Composer plugin: after install/update, if semitexa/core is installed in a
 * semitexa/ultimate project, sync the generated registry (payloads + contracts).
 * Scaffold assets ship directly with semitexa/ultimate and need no init step.
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
        $this->runRegistrySync($php, $bin, $root);
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
