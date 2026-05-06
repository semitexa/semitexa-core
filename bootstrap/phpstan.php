<?php

declare(strict_types=1);

/**
 * Canonical PHPStan bootstrap for Semitexa projects.
 *
 * Referenced from phpstan.neon's `bootstrapFiles` (alongside vendor/autoload.php):
 *
 *     bootstrapFiles:
 *         - vendor/autoload.php
 *         - vendor/semitexa/core/bootstrap/phpstan.php
 *
 * Responsibility: register PSR-4 mappings for local modules
 * (src/modules/<Name>/src) on the live Composer ClassLoader so PHPStan can
 * discover module classes when analyzing files that reference them.
 *
 * Production runtime registers these via the LocalModuleAutoloadPhase build
 * phase; PHPStan never runs that phase, so it needs an explicit hook here.
 *
 * This file does NOT require vendor/autoload.php — PHPStan loads it before
 * running bootstrapFiles, and the registrar's namespace is itself loaded
 * from there.
 *
 * Idempotent.
 */

\Semitexa\Core\Boot\LocalModuleAutoloadRegistrar::register();
