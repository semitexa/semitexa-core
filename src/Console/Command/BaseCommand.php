<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

use Semitexa\Core\Support\ProjectRoot;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Base command with helper methods
 */
abstract class BaseCommand extends Command
{
    private const AUTOLOAD_REBUILT_FLAG = 'SEMITEXA_AUTOLOAD_REBUILT';

    protected function getProjectRoot(): string
    {
        return ProjectRoot::get();
    }

    /**
     * Rebuild Composer autoload classmap so that moved/added/removed classes
     * are picked up by the next worker boot.
     */
    protected function rebuildAutoload(SymfonyStyle $io): bool
    {
        if (getenv(self::AUTOLOAD_REBUILT_FLAG) === '1') {
            $io->text('Autoload classmap already rebuilt during CLI bootstrap.');
            return true;
        }

        $root = $this->getProjectRoot();

        // Prefer system composer if available
        $which = trim((string) shell_exec('which composer 2>/dev/null'));
        if ($which !== '') {
            $composer = escapeshellarg($which);
        } elseif (is_file($root . '/vendor/bin/composer')) {
            $composer = PHP_BINARY . ' ' . escapeshellarg($root . '/vendor/bin/composer');
        } else {
            $io->error('Composer not found. Install it globally or require composer/composer as a dependency.');
            return false;
        }

        $cmd = $composer . ' dump-autoload --working-dir=' . escapeshellarg($root) . ' 2>&1';
        $output = [];
        exec($cmd, $output, $code);

        if ($code !== 0) {
            $io->warning('composer dump-autoload failed: ' . implode("\n", $output));
            return false;
        }

        putenv(self::AUTOLOAD_REBUILT_FLAG . '=1');
        $io->text('Autoload classmap rebuilt.');
        return true;
    }
}
