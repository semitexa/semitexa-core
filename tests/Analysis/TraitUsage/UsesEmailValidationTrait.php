<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Analysis\TraitUsage;

use Semitexa\Core\Validation\Trait\EmailValidationTrait;

/**
 * Exists so PHPStan analyses EmailValidationTrait: a trait nothing in the
 * analysed paths uses is reported as unused and NOT analysed at all. Its real
 * users are application payloads, outside those paths.
 */
final class UsesEmailValidationTrait
{
    use EmailValidationTrait;
}
