<?php

declare(strict_types=1);

namespace Semitexa\Core;

readonly class ProjectContext
{
    public function __construct(
        public string $rootPath,
        public string $varPath,
        public string $modulesPath,
        public string $packagesPath,
    ) {}
}
