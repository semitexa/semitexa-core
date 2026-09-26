<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

use Semitexa\Core\Contract\ContractFactoryInterface;

/**
 * Generic factory for a contract: getDefault(), get(enum $key), keys().
 * The container builds one per factory contract; the generated per-contract
 * class (App\Registry\Contracts\*Factory) wraps it to implement the contract's
 * Factory* interface, whose get() takes the concrete enum.
 *
 * An entry is either a shared (worker-scoped) implementation or a \Closure that
 * resolves an execution-scoped one per call; a contract implementation can
 * never itself be a \Closure, so the two cannot be confused.
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
     * @param object $default Active implementation (by module order or resolver), or a \Closure resolving it.
     * @param array<string, object> $byKey Backed enum value => implementation, or a \Closure resolving it.
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
        return self::resolve($this->default);
    }

    public function get(\BackedEnum $key): object
    {
        $lookup = (string) $key->value;
        if (isset($this->byKey[$lookup])) {
            return self::resolve($this->byKey[$lookup]);
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

    private static function resolve(object $entry): object
    {
        if (!$entry instanceof \Closure) {
            return $entry;
        }
        $resolved = $entry();
        \assert(is_object($resolved));

        return $resolved;
    }
}
