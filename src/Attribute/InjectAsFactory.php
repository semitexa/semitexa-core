<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

use Attribute;

/**
 * Inject the factory for a contract: getDefault(), get(<Enum> $key), keys().
 * Property type must be the contract's Factory* interface (same namespace,
 * e.g. FactoryStorageInterface for StorageInterface). The container injects the
 * class generated for it by `bin/semitexa registry:sync:contracts`
 * (App\Registry\Contracts\*Factory); boot fails if it has not been generated.
 * Only allowed on protected properties.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class InjectAsFactory
{
}
