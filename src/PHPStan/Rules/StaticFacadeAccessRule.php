<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.staticFacadeAccess
 *
 * Pins the outcome of `ep-kill-static-facades`: a facade whose behaviour has
 * moved to a container-managed service must not grow new static call sites.
 * Callers inject the service; the static surface exists only for the frozen
 * tail listed per facade below.
 *
 * `::class` and constant fetches are not calls and stay allowed everywhere —
 * they are metadata, not behaviour riding hidden global state.
 *
 * Currently guards `AsyncResourceSseServer` (service: `SseServer`). As other
 * facades' tails shrink to a sanctioned list, add them here with their own
 * allowlist rather than inventing a second mechanism.
 *
 * @implements Rule<StaticCall>
 */
final class StaticFacadeAccessRule implements Rule
{
    /**
     * Facade FQCN => [service callers should inject, list of exact class or
     * namespace prefixes allowed to keep calling the facade statically].
     *
     * Allowlist entries are the frozen tail, each for a stated reason:
     * the facade itself and its delegate target; the single wiring listener;
     * the two deliberately container-independent lifecycle listeners; static
     * contexts that have no instance to inject into (a static factory, a
     * static helper with static callers); the graphql streamer, whose
     * dependency on ssr is soft (`class_exists`) and so cannot be typed; and
     * the demo heartbeat console command. Tests are exempt wholesale — they
     * exercise the facade deliberately.
     */
    private const GUARDED_FACADES = [
        'Semitexa\\Ssr\\Application\\Service\\Async\\AsyncResourceSseServer' => [
            'Semitexa\\Ssr\\Application\\Service\\Async\\SseServer',
            [
                'Semitexa\\Ssr\\Application\\Service\\Async\\AsyncResourceSseServer',
                'Semitexa\\Ssr\\Application\\Service\\Async\\SseServer',
                'Semitexa\\Ssr\\Application\\Service\\Server\\Lifecycle\\WireCoreInstancesListener',
                'Semitexa\\Ssr\\Application\\Service\\Server\\Lifecycle\\BindAsyncResourceSseServerListener',
                'Semitexa\\Ssr\\Application\\Service\\Server\\Lifecycle\\ReapStaleSubscriptionsListener',
                'Semitexa\\Ssr\\Application\\Service\\Async\\SseAsyncResultDelivery',
                'Semitexa\\Ssr\\Application\\Service\\Async\\SseStreamRequest',
                'Semitexa\\Ssr\\Application\\Service\\Async\\SseDeferredDoor',
                'Semitexa\\Ssr\\Application\\Service\\Async\\SseSessionControlDelivery',
                // The `??=` fallback accessor only: injected in production,
                // but app-module subclasses constructed bare must not explode.
                'Semitexa\\Ssr\\Application\\Handler\\PayloadHandler\\AbstractSseFeedHandler',
                'Semitexa\\Graphql\\Application\\Service\\Runtime\\GraphqlSseSubscriptionStreamer',
                'Semitexa\\Demo\\Application\\Console\\Command\\DemoDeferredHeartbeatCommand',
            ],
        ],
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

        // resolveName() can hand back a leading backslash on fully-qualified
        // call sites; GUARDED_FACADES keys are stored without one - normalise,
        // as the sibling rules do, or \\Fqcn::call() bypasses the guard.
        $className = ltrim($scope->resolveName($node->class), '\\');
        if (!isset(self::GUARDED_FACADES[$className])) {
            return [];
        }

        [$service, $allowed] = self::GUARDED_FACADES[$className];

        $currentClass = $scope->getClassReflection()?->getName() ?? '';
        if (str_contains($currentClass, '\\Tests\\')) {
            return [];
        }

        foreach ($allowed as $prefix) {
            // Exact class, or a namespace boundary after the entry — a bare
            // str_starts_with would also admit name-suffix lookalikes
            // (SseServerHelper riding the SseServer entry).
            if ($currentClass === $prefix || str_starts_with($currentClass, $prefix . '\\')) {
                return [];
            }
        }

        $method = $node->name instanceof Node\Identifier ? $node->name->toString() : '(dynamic)';

        return [
            RuleErrorBuilder::message(
                sprintf(
                    '%s::%s() is a retired static facade call (%s is not on its frozen tail). '
                    . 'Inject %s via #[InjectAsReadonly] instead; the static surface exists only '
                    . 'for wiring listeners and static contexts already on the allowlist in %s.',
                    $className,
                    $method,
                    $currentClass ?: ($scope->getNamespace() ?? '(global)'),
                    $service,
                    self::class,
                )
            )->identifier('semitexa.staticFacadeAccess')->build(),
        ];
    }
}
