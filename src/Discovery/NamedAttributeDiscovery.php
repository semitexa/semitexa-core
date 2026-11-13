<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

/**
 * Finds the classes that carry an attribute while loading only the files that name it
 * (tk-master-loads-no-app-classes).
 *
 * The Swoole master asks for its lifecycle listeners before it forks. ClassDiscovery answers by
 * autoloading every class of the map, which on 2026-11-13 left 4356 files in the master, 501 of
 * them the application's own: every worker forked them, and server:reload, which only respawns
 * workers, could not change any of them. A class that carries an attribute names it in its file
 * (in the attribute itself, or in the `use` that imports it), so a file that does not is not read
 * any further. The listeners themselves are loaded, the application's too: their attribute says
 * which phase they belong to. Measured the same day on the dev stack: 123 files.
 *
 * For the master only. Its answer is never shared with other instances, so a worker's discovery
 * still reads every class and reports every one that does not load.
 */
final class NamedAttributeDiscovery extends ClassDiscovery
{
    /** @var array<string, list<class-string>> */
    private array $found = [];

    /**
     * @return list<class-string>
     */
    public function findClassesWithAttribute(string $attributeClass): array
    {
        if (isset($this->found[$attributeClass])) {
            return $this->found[$attributeClass];
        }
        $name = substr((string) strrchr('\\' . $attributeClass, '\\'), 1);
        $classes = [];
        foreach ($this->getClassMap() as $class => $file) {
            $source = is_file($file) ? file_get_contents($file) : false;
            // Case-insensitive, as PHP matches attribute names (review, core#232).
            if ($source === false || stripos($source, $name) === false) {
                continue;
            }
            try {
                // A module under src/modules is not Composer's to autoload: read its file, as
                // ClassDiscovery does.
                if (!class_exists($class)) {
                    (static function (string $f): void { require_once $f; })($file);
                }
                if (!class_exists($class, false)) {
                    continue;
                }
                if ((new \ReflectionClass($class))->getAttributes($attributeClass) !== []) {
                    $classes[] = $class;
                }
            } catch (\Throwable $e) {
                BootDiagnostics::current()->skip('NamedAttributeDiscovery', "Skipping {$class} (load error): " . $e->getMessage(), $e);
            }
        }

        return $this->found[$attributeClass] = $classes;
    }
}
