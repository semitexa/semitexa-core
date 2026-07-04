<?php

declare(strict_types=1);

/**
 * Local module-structure extension for `packages/semitexa-core`.
 *
 * STRICTLY ADDITIVE (see the orm/ssr extensions for the pattern). semitexa-core
 * predates the structure spec and hosts framework subsystems at src/ root that
 * are core-only public API. This revision authorizes ONLY what a change has
 * actually needed so far — everything else at core's src/ root remains an
 * open Architecture Question and is NOT silently legalized:
 *
 *   - Server/Lifecycle/ — the worker lifecycle subsystem (phases, context,
 *     invoker, registry, listener contract + core-owned listeners such as
 *     ClearWorkerTimersListener/WorkerTimerRegistry). The whole subsystem
 *     already lives here; new members belong next to it, not in a
 *     validator-appeasing location.
 */

use Semitexa\Dev\Application\Service\Ai\Verify\Structure\LocalModuleStructureExtension;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureRule;

if (!class_exists(LocalModuleStructureExtension::class) || !class_exists(ModuleStructureRule::class)) {
    return null;
}

return new LocalModuleStructureExtension(
    package: 'core',
    topLevelDirectories: [
        'Server',
    ],
    pathRules: [
        'Server' => new ModuleStructureRule(
            path: 'Server',
            allowedDirectories: ['Lifecycle'],
            allowedFilePatterns: ['/^[A-Z][A-Za-z0-9]*\.php$/'],
            rationale: 'semitexa-core-only: the Swoole server bootstrap subsystem (SwooleBootstrap, SwooleEvent, …).',
        ),
        'Server/Lifecycle' => new ModuleStructureRule(
            path: 'Server/Lifecycle',
            allowedFilePatterns: ['/^[A-Z][A-Za-z0-9]*\.php$/'],
            mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
            rationale: 'semitexa-core-only: worker lifecycle subsystem (phases, context, invoker, registry, core listeners). PascalCase PHP files; no subdirectories.',
        ),
    ],
    reason: 'semitexa-core hosts the framework server/lifecycle subsystem at src/Server/, which is core-only public API predating the structure spec.',
);
