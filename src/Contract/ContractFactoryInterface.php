<?php

declare(strict_types=1);

namespace Semitexa\Core\Contract;

/**
 * The generic, untyped factory API — what ContractFactory implements and what an
 * #[InjectAsFactory(of: SomeContract::class)] property typed as ContractFactory or
 * ContractFactoryInterface receives.
 *
 * For a typed factory, declare an interface whose name starts with "Factory" (e.g.
 * FactoryItemListProviderInterface) in the same namespace as the base contract; the
 * framework binds it to a generated App\Registry\Contracts\*Factory. That interface must
 * NOT extend this one: get() here takes \BackedEnum, and a child interface narrowing it to
 * a concrete enum is a fatal error when PHP loads it (semitexa.factoryContract flags it).
 * <code>
 * interface FactoryItemListProviderInterface
 * {
 *     public function getDefault(): ItemListProviderInterface;
 *     public function get(ItemListProviderKind $key): ItemListProviderInterface;
 *     public function keys(): array; // @return list<\BackedEnum>
 * }
 * </code>
 *
 * Factory selection is enum-keyed and closed-world. The backed enum defines the complete
 * set of legal implementations. Use getDefault() for the active implementation (by module
 * extends order), or get($key) for a specific implementation.
 */
interface ContractFactoryInterface
{
    /**
     * The default (active) implementation, chosen by module "extends" order.
     */
    public function getDefault(): object;

    /**
     * Get implementation by enum key.
     *
     * @throws \InvalidArgumentException when key is unknown
     */
    public function get(\BackedEnum $key): object;

    /**
     * Available enum cases for this factory.
     *
     * @return list<\BackedEnum>
     */
    public function keys(): array;
}
