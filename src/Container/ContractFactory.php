<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

use Semitexa\Core\Contract\ContractFactoryInterface;

/**
 * Generic factory for a contract: getDefault(), get(enum $key), keys().
 * Used instead of generated per-contract factory classes. The container binds
 * each Factory* interface to an instance of this class configured for that contract.
 */
final class ContractFactory implements ContractFactoryInterface
{
    /** @var object */
    private object $default;

    /** @var array<string, object> */
    private array $byKey;

    /** @var array<string, \BackedEnum> */
    private array $enumKeys;

    /**
     * @param object $default Active implementation (by module order or resolver).
     * @param array<string, object> $byKey Backed enum value => implementation.
     * @param array<string, \BackedEnum> $enumKeys Backed enum value => enum case.
     */
    public function __construct(object $default, array $byKey, array $enumKeys)
    {
        $this->default = $default;
        $this->byKey = $byKey;
        $this->enumKeys = $enumKeys;
    }

    public function getDefault(): object
    {
        return $this->default;
    }

    public function get(\BackedEnum $key): object
    {
        $lookup = (string) $key->value;
        if (isset($this->byKey[$lookup])) {
            return $this->byKey[$lookup];
        }

        $available = implode(', ', array_map(
            static fn(\BackedEnum $case): string => $case::class . '::' . $case->name,
            array_values($this->enumKeys),
        ));

        throw new \InvalidArgumentException('Unknown implementation key: ' . $key::class . '::' . $key->name . '. Available: ' . $available);
    }

    /** @return list<\BackedEnum> */
    public function keys(): array
    {
        return array_values($this->enumKeys);
    }
}
