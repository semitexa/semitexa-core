<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Semitexa\Core\PHPStan\Rules\WorkerServiceConnectionHandleRule;

/**
 * Locks the detection scope + messaging of WorkerServiceConnectionHandleRule.
 *
 * The rule generalises the TransactionManager::$activeConnection fix into a
 * standing guard: a plain #[AsService] worker-singleton must not hold a live
 * connection handle in a mutable instance property, because that one instance is
 * shared across every concurrent request coroutine. The behavioural fire/silent
 * proof runs under PHPStan (fires on a #[AsService] with `?\PDO`, silent on
 * #[ExecutionScoped] / non-service / readonly); these assertions pin the parts a
 * refactor could silently weaken — the contract identifier, the actionable fix in
 * the message, and the readonly/scoping exemptions.
 */
final class WorkerServiceConnectionHandleRuleTest extends TestCase
{
    private function source(): string
    {
        $file = (new ReflectionClass(WorkerServiceConnectionHandleRule::class))->getFileName();
        self::assertIsString($file, 'Cannot locate rule source file');
        $source = file_get_contents($file);
        self::assertIsString($source);

        return $source;
    }

    #[Test]
    public function the_identifier_is_contract_stable(): void
    {
        // The identifier flows verbatim into ai:verify NDJSON and user baselines;
        // changing it silently breaks ignores.
        self::assertStringContainsString(
            "->identifier('semitexa.workerServiceConnectionHandle')",
            $this->source(),
        );
    }

    #[Test]
    public function the_message_states_the_fix_not_just_the_problem(): void
    {
        $source = $this->source();
        self::assertStringContainsString('ConnectionPool', $source, 'points at the coroutine-safe pool');
        self::assertStringContainsString('CoroutineLocal', $source, 'offers the span-a-request escape hatch');
        self::assertStringContainsString('#[ExecutionScoped]', $source, 'names the per-request opt-out');
    }

    #[Test]
    public function it_exempts_readonly_and_static_and_scoped_and_non_service(): void
    {
        $source = $this->source();
        // Injected/config handles are readonly; boot-once statics are not per-request.
        self::assertStringContainsString('$node->isReadonly()', $source);
        self::assertStringContainsString('$node->isStatic()', $source);
        // Per-request-cloned classes are allowed to hold a per-request connection.
        self::assertStringContainsString('SCOPING_ATTRIBUTES', $source);
        // Only container-managed worker-singletons are in scope.
        self::assertStringContainsString('CONTAINER_MANAGED_ATTRIBUTES', $source);
    }

    #[Test]
    public function it_targets_the_connection_handle_shape(): void
    {
        $source = $this->source();
        self::assertStringContainsString("'PDO'", $source, 'catches raw PDO');
        self::assertStringContainsString("'PDOStatement'", $source);
        self::assertStringContainsString("'Connection'", $source, 'catches *Connection types');
    }
}
