<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

use Attribute;

/**
 * Inject the factory for a contract: getDefault(), get(string $key), keys().
 * Property type must be a Factory* interface extending ContractFactoryInterface.
 * Only allowed on protected properties.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class InjectAsFactory
{
}
