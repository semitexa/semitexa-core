<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

use Semitexa\Core\Util\ProjectRoot;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Base command with helper methods
 */
abstract class BaseCommand extends Command
{
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
        $root = $this->getProjectRoot();
        $composer = PHP_BINARY . ' ' . escapeshellarg($root . '/vendor/bin/composer');

        // Prefer system composer if available
        $which = trim((string) shell_exec('which composer 2>/dev/null'));
        if ($which !== '') {
            $composer = escapeshellarg($which);
        }

        $cmd = $composer . ' dump-autoload --working-dir=' . escapeshellarg($root) . ' 2>&1';
        $output = [];
        exec($cmd, $output, $code);

        if ($code !== 0) {
            $io->warning('composer dump-autoload failed: ' . implode("\n", $output));
            return false;
        }

        $io->text('Autoload classmap rebuilt.');
        return true;
    }
}

