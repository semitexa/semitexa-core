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
 *
 * A property typed as the generic ContractFactory / ContractFactoryInterface
 * receives the container's generic factory instead; its type names no contract,
 * so it must say which one: #[InjectAsFactory(of: StorageInterface::class)].
 * Only allowed on protected properties.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class InjectAsFactory
{
    /**
     * @param class-string|null $of The contract whose factory to inject; required
     *        only when the property is typed as the generic factory.
     */
    public function __construct(
        public readonly ?string $of = null,
    ) {
    }
}
