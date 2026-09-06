<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.staticContainerAccess
 *
 * Flags ContainerFactory:: calls outside Semitexa core/runtime internals.
 * Any usage in application modules, handlers, services, listeners, repositories,
 * or package business logic is an error.
 *
 * @implements Rule<StaticCall>
 */
final class StaticContainerAccessRule implements Rule
{
    /**
     * Namespaces where ContainerFactory usage is allowed — core internals,
     * plus the narrow dynamic-dispatch tier: infrastructure whose whole job
     * is resolving an arbitrary, runtime-named #[AsService] class (queue
     * consumers, the scheduler's job executor). Dynamic dispatch is what the
     * container is FOR; there is no attribute-injection shape for a class
     * name that only exists in a database row.
     *
     * Every entry here is a NAMESPACE and must end in a backslash. A class name
     * used as a prefix blesses more than it names: 'Semitexa\\Core\\Application'
     * sat here to permit the Application class and silently permitted the whole
     * 'Semitexa\\Core\\Application\\' namespace with it — TestHandlerCommand was
     * exempt without anyone deciding that — and would equally have permitted a
     * future 'ApplicationWorker'. Class names belong in
     * {@see ALLOWED_EXACT_CLASSES}, which compares with ===.
     */
    private const ALLOWED_NAMESPACES = [
        'Semitexa\\Core\\Container\\',
        'Semitexa\\Core\\Console\\',
        'Semitexa\\Core\\Server\\',
        'Semitexa\\Core\\Queue\\',
    ];

    /**
     * Compared with `===`, never as a prefix: a prefix entry would also bless
     * every class NAMED LIKE the exception (ReplayRunnerHelper, RunExecutorX),
     * which is exactly the drift an exact blessing exists to prevent. The
     * replay sandbox's whole job is building an isolated request scope around
     * a handler resolved from a recorded route — the queue-consumer tier.
     * Nothing else in semitexa-dev gets this.
     */
    private const ALLOWED_EXACT_CLASSES = [
        // The dynamic-dispatch tier, each named rather than inherited from a
        // prefix. Moved here 2026-09-06 from ALLOWED_NAMESPACES, where they were
        // class names doing prefix matching.
        'Semitexa\\Core\\Application',
        'Semitexa\\Core\\Log\\StaticLoggerBridge',
        'Semitexa\\Core\\Event\\EventDispatcher',
        'Semitexa\\Scheduler\\Application\\Service\\RunExecutor',
        // Was exempt only as collateral of the 'Semitexa\\Core\\Application'
        // prefix. It is a diagnostic that resolves a handler class named on the
        // command line — genuinely the dispatch tier — so it is named here on
        // purpose instead of inherited by accident.
        'Semitexa\\Core\\Application\\Console\\Command\\TestHandlerCommand',
        'Semitexa\\Dev\\Application\\Service\\Trace\\ReplayRunner',
    ];

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->class instanceof Node\Name) {
            return [];
        }

        $className = $node->class->toString();
        if ($className !== 'Semitexa\\Core\\Container\\ContainerFactory'
            && $className !== 'ContainerFactory') {
            return [];
        }

        $currentClass = $scope->getClassReflection()?->getName() ?? '';
        $currentNamespace = $scope->getNamespace() ?? '';

        if (in_array($currentClass, self::ALLOWED_EXACT_CLASSES, true)) {
            return [];
        }

        foreach (self::ALLOWED_NAMESPACES as $allowed) {
            if (str_starts_with($currentClass, $allowed) || str_starts_with($currentNamespace . '\\', $allowed)) {
                return [];
            }
        }

        return [
            RuleErrorBuilder::message(
                sprintf(
                    'Static ContainerFactory:: access is forbidden in application code (%s). '
                    . 'Use #[InjectAsReadonly], #[InjectAsMutable], #[InjectAsFactory] property injection instead.',
                    $currentClass ?: $currentNamespace,
                )
            )->identifier('semitexa.staticContainerAccess')->build(),
        ];
    }
}
