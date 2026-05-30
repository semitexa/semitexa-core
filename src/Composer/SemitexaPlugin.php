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
            ScriptEvents::PRE_AUTOLOAD_DUMP => ['onPreAutoloadDump', 0],
        ];
    }

    /**
     * Single source of truth for test PSR-4 namespaces.
     *
     * Derives the `Semitexa\<Module>\Tests\ → packages/semitexa-<module>/tests/`
     * dev-autoload map from on-disk `packages/*\/tests` at every autoload dump,
     * so it tracks reality and cannot drift like a hand-maintained list. Runs on
     * install, update, AND bare `composer dump-autoload`.
     *
     * Fixture-gated: a package gets a PSR-4 entry ONLY when its tests dir holds a
     * non-`*Test.php` PHP class (a Fixture / shared base class loaded by FQCN).
     * Packages whose tests are only `*Test.php` need no PSR-4 — PHPUnit's
     * directory scan requires those files directly — so they are intentionally
     * omitted. This drops the vestigial/stale entries (e.g. ssr, blockchain,
     * ledger) and auto-includes any package the moment it grows a real fixture.
     */
    public function onPreAutoloadDump(Event $event): void
    {
        $root = \dirname($this->composer->getConfig()->get('vendor-dir'));
        $packagesDir = $root . '/packages';
        if (!\is_dir($packagesDir)) {
            return;
        }

        $map = [];
        $dirs = glob($packagesDir . '/semitexa-*/tests', GLOB_ONLYDIR) ?: [];
        foreach ($dirs as $testsDir) {
            if (!$this->testsDirHasFixtures($testsDir)) {
                continue;
            }
            $packageDir = \basename(\dirname($testsDir));
            $namespace = 'Semitexa\\' . $this->studlyModule($packageDir) . '\\Tests\\';
            $map[$namespace] = 'packages/' . $packageDir . '/tests/';
        }

        if ($map === []) {
            return;
        }

        ksort($map);

        $package = $this->composer->getPackage();
        $devAutoload = $package->getDevAutoload();
        $devAutoload['psr-4'] = array_merge($devAutoload['psr-4'] ?? [], $map);
        $package->setDevAutoload($devAutoload);

        $this->io->write(sprintf(
            '<info>Semitexa: generated %d test PSR-4 namespace(s) from packages/*/tests</info>',
            \count($map),
        ));
    }

    /**
     * True when the tests dir contains at least one PHP file that is NOT a
     * `*Test.php` test case — i.e. a Fixture or shared base class that can only
     * be loaded by FQCN and therefore needs a PSR-4 entry.
     */
    private function testsDirHasFixtures(string $testsDir): bool
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($testsDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $name = $file->getFilename();
            if (\substr($name, -4) !== '.php') {
                continue;
            }
            if (\substr($name, -8) === 'Test.php') {
                continue;
            }
            return true;
        }

        return false;
    }

    /**
     * `semitexa-project-graph` → `ProjectGraph`, `semitexa-api` → `Api`.
     */
    private function studlyModule(string $packageDir): string
    {
        $module = \substr($packageDir, \strlen('semitexa-'));
        $parts = explode('-', $module);

        return implode('', array_map('ucfirst', $parts));
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
