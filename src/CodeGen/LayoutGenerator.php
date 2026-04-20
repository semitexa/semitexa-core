<?php

declare(strict_types=1);

namespace Semitexa\Core\CodeGen;

use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Support\Str;
use Semitexa\Core\Support\ProjectRoot;

class LayoutGenerator
{
    private bool $bootstrapped = false;
    /** @var array<int, array<string, mixed>> */
    private array $layouts = [];

    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
    ) {
    }

    /**
     * Generate (or refresh) a layout copy for the given identifier.
     *
     * Identifier formats:
     *  - handle (e.g. "login") if unique across modules
     *  - Module/handle (e.g. "UserFrontend/login")
     *  - module-handle/handle (e.g. "module-user-frontend/login")
     */
    public function generate(string $identifier, ?\Symfony\Component\Console\Style\SymfonyStyle $io = null): void
    {
        $layouts = $this->bootstrap();
        $target = $this->resolveTarget($layouts, $identifier);
        if ($target === null) {
            throw new \RuntimeException("Layout '{$identifier}' not found. Use 'Module/handle' if the handle is duplicated.");
        }

        $this->writeLayout($target, false, $io);
    }

    /**
     * Generate layout copies for every discovered module layout.
     */
    public function generateAll(?\Symfony\Component\Console\Style\SymfonyStyle $io = null): void
    {
        $layouts = $this->bootstrap();
        $generated = 0;

        foreach ($layouts as $layout) {
            if ($this->writeLayout($layout, true, $io)) {
                $generated++;
            }
        }

        if ($generated === 0) {
            if ($io) {
                $io->info('No new layouts to copy – everything already exists in src/.');
            } else {
                echo "ℹ️  No new layouts to copy – everything already exists in src/.\n";
            }
        } else {
            if ($io) {
                $io->success("Copied {$generated} layout(s) into src/modules/*/Layout.");
            } else {
                echo "✨ Copied {$generated} layout(s) into src/modules/*/Layout.\n";
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function bootstrap(): array
    {
        if (!$this->bootstrapped) {
            $this->layouts = $this->collectLayouts();
            $this->bootstrapped = true;
        }

        return $this->layouts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectLayouts(): array
    {
        $result = [];
        $projectRoot = ProjectRoot::get();

        foreach ($this->moduleRegistry->getModules() as $module) {
            $modulePath = $module['path'];
            $layoutDir = rtrim($modulePath, '/') . '/src/Application/View/templates/layout';
            if (!is_dir($layoutDir)) {
                $layoutDir = rtrim($modulePath, '/') . '/Application/View/templates/layout';
                if (!is_dir($layoutDir)) {
                    continue;
                }
            }

            $files = glob($layoutDir . '/*.html.twig') ?: [];
            if (empty($files)) {
                continue;
            }

            $studly = Str::toStudly($module['name']);

            foreach ($files as $file) {
                $handle = basename($file, '.html.twig');
                $result[] = [
                    'id' => $studly . '/' . $handle,
                    'handle' => $handle,
                    'module' => $module['name'],
                    'moduleStudly' => $studly,
                    'source' => $file,
                    'destination' => $projectRoot . '/src/modules/' . $studly . '/Application/View/templates/layout/' . basename($file),
                ];
            }
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $layouts
     * @return array<string, mixed>|null
     */
    private function resolveTarget(array $layouts, string $identifier): ?array
    {
        $normalized = strtolower($identifier);
        foreach ($layouts as $layout) {
            if (strtolower($layout['id']) === $normalized) {
                return $layout;
            }
        }

        $matches = array_values(array_filter(
            $layouts,
            fn ($layout) => strtolower($layout['handle']) === $normalized
                || strtolower($layout['module'] . '/' . $layout['handle']) === $normalized
        ));

        if (count($matches) === 1) {
            return $matches[0];
        }

        if (count($matches) > 1) {
            $matchedIds = [];
            foreach ($matches as $layout) {
                $id = $layout['id'] ?? null;
                if (!is_string($id) || $id === '') {
                    continue;
                }
                $matchedIds[] = $id;
            }

            $list = implode("\n - ", $matchedIds);
            throw new \RuntimeException("Ambiguous layout identifier '{$identifier}'. Matches:\n - {$list}");
        }

        return null;
    }

    /**
     * @param array<string, mixed> $layout
     */
    private function writeLayout(array $layout, bool $silentSkip = false, ?\Symfony\Component\Console\Style\SymfonyStyle $io = null): bool
    {
        $destination = $layout['destination'];
        $dir = dirname($destination);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        if (is_file($destination)) {
            if (!$silentSkip) {
                if ($io) {
                    $io->note("Layout {$layout['id']} already exists. Skipping.");
                } else {
                    echo "↩️  Layout {$layout['id']} already exists. Skipping.\n";
                }
            }
            return false;
        }

        $contents = file_get_contents($layout['source']);
        if ($contents === false) {
            throw new \RuntimeException("Failed to read layout source: {$layout['source']}");
        }

        $comment = "{# AUTO-GENERATED FROM {$layout['module']}/{$layout['handle']}. Edit freely in src/. #}\n";
        $payload = $comment . $contents;

        if (file_put_contents($destination, $payload) === false) {
            throw new \RuntimeException("Unable to write layout copy to {$destination}");
        }

        if ($io) {
            $io->success("Layout {$layout['id']} copied to {$destination}");
        } else {
            echo "✅ Layout {$layout['id']} copied to {$destination}\n";
        }
        return true;
    }


}

