<?php

declare(strict_types=1);

namespace Semitexa\Core\Contract;

/**
 * Base interface for "Factory of implementations" contracts.
 *
 * When an interface name starts with "Factory" (e.g. FactoryItemListProviderInterface),
 * the framework binds it to a generated implementation that lets the developer choose
 * from all registered implementations of the base contract (e.g. ItemListProviderInterface).
 *
 * Define your factory interface in the same namespace as the base contract:
 * <code>
 * interface FactoryItemListProviderInterface extends ContractFactoryInterface
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
