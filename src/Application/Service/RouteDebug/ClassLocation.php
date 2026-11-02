<?php

declare(strict_types=1);

namespace Semitexa\Core\Application\Service\RouteDebug;

/** A class routes:show names, with the module that owns it and the file it is in. */
final readonly class ClassLocation
{
    public function __construct(
        public string $class,
        public string $module,
        public ?string $file,
    ) {}

    /** @return array{class: string, module: string, file: ?string} */
    public function toArray(): array
    {
        return ['class' => $this->class, 'module' => $this->module, 'file' => $this->file];
    }
}
