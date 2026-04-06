<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Runtime;

use Semitexa\Core\Support\ProjectRoot;
use Symfony\Component\Console\Style\SymfonyStyle;

final class PrepareRuntimeAction
{
    public function __construct(private readonly SymfonyStyle $io) {}

    /**
     * Run composer dump-autoload and write build marker.
     * Returns false on failure (caller must abort).
     */
    public function execute(): bool
    {
        $this->io->text('<info>[prepare]</info> Running composer dump-autoload...');

        if (!$this->rebuildAutoload()) {
            return false;
        }

        // Clear caches (Twig, etc.) so stale compiled templates don't survive restart
        $cacheAction = new ClearCacheAction($this->io);
        $cacheAction->execute();

        return $this->writeBuildMarker();
    }

    private function rebuildAutoload(): bool
    {
        if (getenv('SEMITEXA_AUTOLOAD_REBUILT') === '1') {
            $this->io->text('Autoload classmap already rebuilt during CLI bootstrap.');
            return $this->writeBuildMarker();
        }

        $root = ProjectRoot::get();

        $composer = $this->resolveComposer($root);
        if ($composer === null) {
            $this->io->error('Composer not found. Install it globally or require composer/composer as a dependency.');
            return false;
        }

        $cmd = $composer . ' dump-autoload --working-dir=' . escapeshellarg($root) . ' 2>&1';
        $output = [];
        exec($cmd, $output, $code);

        if ($code !== 0) {
            $this->io->error('composer dump-autoload failed: ' . implode("\n", $output));
            return false;
        }

        putenv('SEMITEXA_AUTOLOAD_REBUILT=1');
        $this->io->text('Autoload classmap rebuilt.');
        return $this->writeBuildMarker();
    }

    private function writeBuildMarker(): bool
    {
        $root = ProjectRoot::get();
        $classMapFile = $root . '/vendor/composer/autoload_classmap.php';

        $hash = 'unknown';
        if (is_file($classMapFile)) {
            $hash = substr(hash('sha256', (string) filemtime($classMapFile) . '.' . microtime(true)), 0, 12);
        }

        $markerDir = $root . '/var/runtime';
        if (!is_dir($markerDir)) {
            if (!@mkdir($markerDir, 0755, true) && !is_dir($markerDir)) {
                $this->io->error("Failed to create runtime marker directory: {$markerDir}");
                return false;
            }
        }

        if (file_put_contents($markerDir . '/build.hash', $hash) === false) {
            $this->io->error("Failed to write build marker: {$markerDir}/build.hash ({$hash})");
            return false;
        }
        $this->io->text("<info>[prepare]</info> Build marker written: {$hash}");
        return true;
    }

    private function resolveComposer(string $projectRoot): ?string
    {
        $which = trim((string) shell_exec('which composer 2>/dev/null'));
        if ($which !== '') {
            return escapeshellarg($which);
        }

        if (is_file($projectRoot . '/vendor/bin/composer')) {
            return PHP_BINARY . ' ' . escapeshellarg($projectRoot . '/vendor/bin/composer');
        }

        return null;
    }
}
