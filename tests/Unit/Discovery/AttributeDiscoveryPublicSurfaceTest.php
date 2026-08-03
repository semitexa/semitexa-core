<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Semitexa\Core\Discovery\AttributeDiscovery;

/**
 * Characterization pin for the {@see AttributeDiscovery} public surface.
 *
 * AttributeDiscovery is the boot engine: every worker start runs it, and
 * `ai:review-graph:impact` puts 290 classes at depth 5 downstream of it. Unlike
 * the SSE facade it is an *instance* service resolved from the container, so the
 * property that makes a strangler safe here is not "everything is static" but
 * "the constructor's wiring shape and the accessor surface do not move" —
 * collaborators may appear behind it, callers may not have to change.
 *
 * `ep-slay-attribute-discovery` splits the 1297-line body into cohesive
 * collaborators. This test freezes the shape that split must preserve. It is
 * deliberately brittle: any added, removed, renamed or re-typed public member
 * fails it. A failure is not noise to silence — it is the signal to decide,
 * explicitly, whether the change is a BC break for the 35 non-test call sites,
 * and to update the frozen map in the same commit that makes the decision.
 *
 * Scope note: this pins shape, never behaviour. Behavioural characterization of
 * the attribute merge/override rules lives in {@see RouteTransportMetadataTest},
 * which reaches three *private static* methods through reflection
 * (`mergeRequestAttributes`, `applyRequestDefaults`, `assertSseGateCoherence`).
 * Those reflection entry points are this class's hidden second contract: an
 * extraction that moves one of them must migrate its test to the new home in the
 * same commit, or the suite goes red for a reason that looks unrelated.
 */
final class AttributeDiscoveryPublicSurfaceTest extends TestCase
{
    /**
     * Every public method declared on the class, normalized to
     * `function(<type> $name[=], …): <type>`.
     *
     * @var array<string, string>
     */
    private const FROZEN_METHODS = [
        // tk-ad-source-origin appended $sourceOrigin. Reviewed and accepted as BC:
        // the slot is optional and defaults to `new SourceOrigin()`, so all six
        // existing construction sites — four production, two in ReRunUnitTest —
        // keep working unchanged. Every later tier must extend this tail the same
        // way; the required-parameter test below is what enforces it.
        '__construct' => 'function(Semitexa\Core\Discovery\ClassDiscovery $classDiscovery, Semitexa\Core\ModuleRegistry $moduleRegistry, Semitexa\Core\Discovery\RouteRegistry $routeRegistry, ?Semitexa\Core\Discovery\HandlerRegistry $handlerRegistry=, ?Semitexa\Core\Discovery\PayloadPartRegistry $payloadPartRegistry=, ?Semitexa\Core\Discovery\SourceOrigin $sourceOrigin=): mixed',
        'findRoute' => 'function(string $path, string $method=): ?array',
        'findRouteByName' => 'function(string $name): ?array',
        'getDiscoveredPayloadHandlerClassNames' => 'function(): array',
        'getEnrichedRoutes' => 'function(): array',
        'getHandlerRegistry' => 'function(): Semitexa\Core\Discovery\HandlerRegistry',
        'getPayloadPartRegistry' => 'function(): Semitexa\Core\Discovery\PayloadPartRegistry',
        'getPayloadPartsForClass' => 'function(string $requestClass): array',
        'getResolvedResponseAttributes' => 'function(string $class): ?array',
        'getResourcePartsForClass' => 'function(string $responseClass): array',
        'getRoutes' => 'function(): array',
        'initialize' => 'function(): void',
    ];

    #[Test]
    public function public_method_surface_is_unchanged(): void
    {
        self::assertSame(
            self::FROZEN_METHODS,
            self::describePublicMethods(),
            'The AttributeDiscovery public surface moved. 35 non-test files call into it and the '
            . 'container wires it by constructor signature; confirm the change is intentional and BC, '
            . 'then update FROZEN_METHODS in this commit.',
        );
    }

    #[Test]
    public function the_three_optional_constructor_tail_slots_stay_optional(): void
    {
        // The container resolves AttributeDiscovery positionally and several
        // tests build it with three arguments. Extractions will want to inject
        // new collaborators; they may only be appended as OPTIONAL parameters,
        // because a new required slot silently breaks every existing call site
        // at runtime rather than at analysis time.
        $constructor = (new ReflectionClass(AttributeDiscovery::class))->getConstructor();
        self::assertNotNull($constructor);

        $required = array_values(array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            array_filter(
                $constructor->getParameters(),
                static fn (\ReflectionParameter $p): bool => !$p->isOptional(),
            ),
        ));

        self::assertSame(['classDiscovery', 'moduleRegistry', 'routeRegistry'], $required);
    }

    #[Test]
    public function no_state_leaks_out_as_a_public_property(): void
    {
        // Every discovery result is reachable only through an accessor. That is
        // what lets a tier move a backing array into a collaborator without any
        // caller noticing — the moment a property goes public, that freedom is
        // gone and the strangler has to touch call sites instead.
        $public = array_values(array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            (new ReflectionClass(AttributeDiscovery::class))->getProperties(\ReflectionProperty::IS_PUBLIC),
        ));

        self::assertSame([], $public);
    }

    #[Test]
    public function the_class_exposes_no_static_entry_points(): void
    {
        // Mirror of the SSE facade invariant, inverted: AttributeDiscovery is
        // reached only through a container-resolved instance. A public static
        // would be a second, un-injectable way in — exactly the global-namespace
        // shape ep-slay-sse-god-class spent thirteen tasks removing.
        $statics = array_values(array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            array_filter(
                self::declaredPublicMethods(),
                static fn (ReflectionMethod $m): bool => $m->isStatic(),
            ),
        ));

        self::assertSame([], $statics);
    }

    /**
     * @return array<string, string>
     */
    private static function describePublicMethods(): array
    {
        $described = [];
        foreach (self::declaredPublicMethods() as $method) {
            $parameters = [];
            foreach ($method->getParameters() as $parameter) {
                $parameters[] = self::describeType($parameter->getType())
                    . ' $' . $parameter->getName()
                    . ($parameter->isOptional() ? '=' : '');
            }

            $described[$method->getName()] = ($method->isStatic() ? 'static ' : '')
                . 'function(' . implode(', ', $parameters) . '): '
                . self::describeType($method->getReturnType());
        }
        ksort($described);

        return $described;
    }

    /**
     * @return list<ReflectionMethod>
     */
    private static function declaredPublicMethods(): array
    {
        $class = new ReflectionClass(AttributeDiscovery::class);

        return array_values(array_filter(
            $class->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class->getName(),
        ));
    }

    private static function describeType(?\ReflectionType $type): string
    {
        if ($type === null) {
            return 'mixed';
        }

        if (!$type instanceof ReflectionNamedType) {
            // Union / intersection types stringify to their declared form,
            // which is stable enough to pin.
            return (string) $type;
        }

        // `?X` and `X|null` are the same declaration to a caller; normalize to
        // the short form so a cosmetic rewrite does not read as a BC break.
        // `mixed` is implicitly nullable and must not gain a `?`.
        $nullable = $type->allowsNull() && $type->getName() !== 'mixed';

        return ($nullable ? '?' : '') . $type->getName();
    }
}
