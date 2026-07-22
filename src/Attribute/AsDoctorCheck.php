<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

use Attribute;

/**
 * Marks a class as an environment doctor check, discovered and run by the
 * 'system:doctor' command. The class must implement DoctorCheckInterface and
 * have a parameterless constructor — checks are dependency-free probes of the
 * runtime environment, not container-managed services.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class AsDoctorCheck
{
    /**
     * @param string $name Short check identifier shown in the report (e.g. "media.imagick-coders")
     * @param string $package Owning package label (e.g. "semitexa/media")
     */
    public function __construct(
        public string $name,
        public string $package = '',
    ) {
    }
}
