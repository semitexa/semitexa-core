<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Semitexa\Core\Attribute\AsEventListener;
use Semitexa\Core\Attribute\AsPipelineListener;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\ExecutionScoped;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Core\Attribute\SatisfiesServiceContract;

/**
 * semitexa.workerServiceConnectionHandle
 *
 * A plain #[AsService] (or contract-satisfying) class is a WORKER SINGLETON: one
 * instance serves every concurrent request coroutine on the worker. Storing a
 * live connection handle (\PDO, \PDOStatement, \Redis, a *Connection) in a
 * MUTABLE instance property therefore shares that handle across coroutines.
 * Under Swoole a query yields on the socket, so a second coroutine runs on the
 * same connection mid-statement — corrupting overlapping transactions and, when
 * a per-request connection is swapped into the field, cross-leaking one request's
 * connection into another. This is exactly the TransactionManager::$activeConnection
 * trap (fixed by moving that state into CoroutineLocal).
 *
 * The fix: take a connection from the coroutine-safe ConnectionPool per operation,
 * or keep the handle in CoroutineLocal when it must span calls within one request.
 * The classes that legitimately OWN a connection (the pool/adapter implementations)
 * are bound by interface, not #[AsService], so this rule never reaches them; a
 * class that is genuinely cloned per request opts out with #[ExecutionScoped].
 *
 * Deliberately narrow (only live connection handles, only mutable/non-readonly
 * fields) so the baseline is clean and every hit is a real cross-coroutine
 * connection-sharing bug.
 *
 * @implements Rule<Property>
 */
final class WorkerServiceConnectionHandleRule implements Rule
{
    private const CONTAINER_MANAGED_ATTRIBUTES = [
        AsService::class,
        SatisfiesServiceContract::class,
        SatisfiesRepositoryContract::class,
        'Semitexa\\Orm\\Attribute\\AsRepository',
        'AsService',
        'SatisfiesServiceContract',
        'SatisfiesRepositoryContract',
        'AsRepository',
    ];

    private const SCOPING_ATTRIBUTES = [
        ExecutionScoped::class,
        AsPayloadHandler::class,
        AsEventListener::class,
        AsPipelineListener::class,
        'ExecutionScoped',
        'AsPayloadHandler',
        'AsEventListener',
        'AsPipelineListener',
    ];

    /** Concrete connection-handle types (matched on the short name). */
    private const CONNECTION_SHORT_NAMES = [
        'PDO',
        'PDOStatement',
        'Redis',
    ];

    public function getNodeType(): string
    {
        return Property::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        // Injected/config state is readonly; boot-once statics are not per-request.
        if ($node->isReadonly() || $node->isStatic()) {
            return [];
        }

        $typeName = $this->connectionHandleTypeName($node->type);
        if ($typeName === null) {
            return [];
        }

        $classReflection = $scope->getClassReflection();
        if ($classReflection === null) {
            return [];
        }

        $nativeReflection = $classReflection->getNativeReflection();
        if (!$this->hasAnyAttribute($nativeReflection, self::CONTAINER_MANAGED_ATTRIBUTES)) {
            return [];
        }

        // Per-request-cloned classes may safely hold a per-request connection.
        if ($this->hasAnyAttribute($nativeReflection, self::SCOPING_ATTRIBUTES)) {
            return [];
        }

        $propName = $node->props[0]->name->name ?? 'unknown';

        return [
            RuleErrorBuilder::message(
                sprintf(
                    'Mutable property $%s (%s) on worker-singleton service %s holds a live connection handle. '
                    . 'One #[AsService] instance is shared across every concurrent request coroutine, so a shared '
                    . 'connection lets a second coroutine run on it mid-statement and corrupts overlapping '
                    . 'transactions. Take a connection from the ConnectionPool per operation, or keep the handle in '
                    . 'CoroutineLocal if it must span calls within one request; use #[ExecutionScoped] only if this '
                    . 'class is genuinely cloned per request.',
                    $propName,
                    $typeName,
                    $classReflection->getName(),
                )
            )->identifier('semitexa.workerServiceConnectionHandle')->build(),
        ];
    }

    /**
     * Return the written type name if the property type is a live connection
     * handle (\PDO, \PDOStatement, \Redis, or a *Connection / *ConnectionInterface),
     * unwrapping a nullable (?T). Returns null otherwise.
     */
    private function connectionHandleTypeName(?Node $type): ?string
    {
        if ($type instanceof Node\NullableType) {
            $type = $type->type;
        }

        if (!$type instanceof Node\Name) {
            return null;
        }

        $written = $type->toString();
        $short = $type->getLast();

        if (in_array($short, self::CONNECTION_SHORT_NAMES, true)) {
            return $written;
        }

        if (str_ends_with($short, 'Connection') || str_ends_with($short, 'ConnectionInterface')) {
            return $written;
        }

        return null;
    }

    /**
     * @param list<string> $attributeNames
     */
    private function hasAnyAttribute(\ReflectionClass $nativeReflection, array $attributeNames): bool
    {
        foreach ($attributeNames as $attributeName) {
            if ($nativeReflection->getAttributes($attributeName) !== []) {
                return true;
            }
        }

        return false;
    }
}
