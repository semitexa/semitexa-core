<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;
use Semitexa\Core\Attribute\AbstractPayloadRoute;
use Semitexa\Core\Attribute\SseGateModel;
use Semitexa\Core\Attribute\TransportType;

/**
 * Core-side, production metadata builder for routable payloads (Multi-Modal
 * API — Mode 4 / OPTIONS).
 *
 * The metadata counterpart to {@see PayloadHydrator}: where the hydrator fills
 * a payload from a request, this reflects a payload's TYPE into a description.
 * It productionizes the reflection pattern proven by
 * `Semitexa\Testing\Factory\PayloadMetadataFactory` — route attribute via
 * `IS_INSTANCEOF`, access type by walking the class hierarchy (PHP attributes
 * are NOT inherited), and field shape from setters + public properties. The
 * *shape and logic* are lifted; semitexa-core does NOT depend on
 * semitexa-testing, and this reflector references only core types so the
 * `semitexa-core → (no upward dep on) semitexa-authorization` rule holds (the
 * concrete `AsProtectedPayload`/`AsServicePayload` attributes are read via the
 * `AbstractPayloadRoute` base only).
 *
 * The emitted document is a pure projection of the payload TYPE. Business
 * rules expressed inside a payload's `ValidatablePayloadInterface::validate()`
 * body are NOT reflectable — OPTIONS reports type-level shape only (a field
 * with a default is `required: false` even if `validate()` rejects it blank).
 * The front-end must still submit and let the server's `validate()` be the
 * authority; OPTIONS is an advisory hint, not the gate.
 */
final class PayloadMetadataReflector
{
    /**
     * The property/parameter marker that names Mode-3-mutable filter fields
     * ({@see \Semitexa\Core\Attribute\LiveFilterParam}). Introduced in Phase 1
     * (advertisement only — the view-change command intake + filter-only re-run
     * that HONOR it are Phase 2). The `modes`/`fields` derivations below still
     * guard on `class_exists` against this FQCN, so the `sse-update` mode and the
     * per-field `filter` flag light up only for payloads that actually carry the
     * marker, and the reflector stays decoupled from where the marker is applied.
     */
    private const LIVE_FILTER_PARAM_ATTRIBUTE = 'Semitexa\\Core\\Attribute\\LiveFilterParam';

    /** @var array<class-string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * Build the canonical OPTIONS metadata document for a routable payload.
     *
     * @param class-string $payloadClass
     * @return array<string, mixed>
     */
    public static function describe(string $payloadClass): array
    {
        if (isset(self::$cache[$payloadClass])) {
            return self::$cache[$payloadClass];
        }

        $ref = new ReflectionClass($payloadClass);

        // Route attribute — one of AsPublicPayload/AsProtectedPayload/AsServicePayload,
        // all extending AbstractPayloadRoute. Read off the concrete class directly
        // (same as PayloadMetadataFactory): path/methods/transport live on the
        // attribute the class declares.
        $routeAttrs = class_exists(AbstractPayloadRoute::class)
            ? $ref->getAttributes(AbstractPayloadRoute::class, ReflectionAttribute::IS_INSTANCEOF)
            : [];
        $route = $routeAttrs !== [] ? $routeAttrs[0]->newInstance() : null;

        $path = is_string($route?->path) ? $route->path : '/';
        $methods = is_array($route?->methods) && $route->methods !== []
            ? array_values($route->methods)
            : ['GET'];
        $transport = $route?->transport instanceof TransportType
            ? $route->transport->value
            : TransportType::Http->value;

        $access = self::resolveAccessType($ref);
        $fields = self::collectFields($ref);

        $document = [
            'endpoint'  => $path,
            'methods'   => $methods,
            'access'    => $access,
            'transport' => $transport,
            'modes'     => self::deriveModes($transport, $ref),
            'fields'    => $fields,
            // `invalidatedBy` is intentionally omitted: the route field does not
            // exist yet (Phase 2). The document is forward-compatible — absent
            // fields simply do not appear.
        ];

        // `sseGateModel` — surfaced so an SSE client can read, on init, HOW the
        // held-open stream is gated (BearerSession / ChannelToken / Subject). The
        // field is a non-backed enum; advertise its case name. Present only when
        // the route declares it (i.e. SSE routes); forward-compatible — a route
        // without a gate model simply omits the key.
        if ($route?->sseGateModel instanceof SseGateModel) {
            $document['sseGateModel'] = $route->sseGateModel->name;
        }

        self::$cache[$payloadClass] = $document;

        return $document;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * `modes` derivation (Phase 0 design §3.3) — honest and marker-driven, not
     * hard-coded:
     *
     *   modes = ["plain"]                  // every routable payload supports Mode 1
     *   if transport === Sse:
     *       modes += ["sse"]               // Mode 2 connect
     *       if any field carries #[LiveFilterParam]:
     *           modes += ["sse-update"]    // Mode 3 filter-update
     *
     * The SSE boot guard ({@see \Semitexa\Core\Discovery\AttributeDiscovery}
     * assertSseGateCoherence) does NOT force `access != Public` for SSE — a
     * public stream is legal when it declares an in-handler gate model
     * (SseGateModel::ChannelToken / ::BearerSession); only a Subject-gated stream
     * must be non-public. A real SSE endpoint exists today and is public:
     * `/__semitexa_kiss` (BearerSession). So this `sse` branch is live, not
     * dormant. The `sse-update` sub-branch additionally depends on the
     * `#[LiveFilterParam]` marker being present on at least one field, and is
     * guarded accordingly — so it is advertised only for payloads that opt in.
     *
     * @return list<string>
     */
    private static function deriveModes(string $transport, ReflectionClass $ref): array
    {
        $modes = ['plain'];

        if ($transport === TransportType::Sse->value) {
            $modes[] = 'sse';
            if (self::hasLiveFilterParam($ref)) {
                $modes[] = 'sse-update';
            }
        }

        return $modes;
    }

    /**
     * Walk the class hierarchy to find the AbstractPayloadRoute attribute and
     * return its access classification. PHP attributes are not inherited, so
     * parent classes must be checked explicitly. Defaults to 'protected' when
     * no access attribute is found (the safer-than-public default; discovery
     * rejects truly attribute-less routables elsewhere).
     *
     * @return 'public'|'protected'|'service'
     */
    private static function resolveAccessType(ReflectionClass $ref): string
    {
        if (!class_exists(AbstractPayloadRoute::class)) {
            return 'protected';
        }

        $current = $ref;
        while ($current !== false) {
            $attrs = $current->getAttributes(AbstractPayloadRoute::class, ReflectionAttribute::IS_INSTANCEOF);
            if ($attrs !== []) {
                $type = $attrs[0]->newInstance()->getAccessType();
                $value = is_object($type) && property_exists($type, 'value') ? $type->value : $type;

                return match ((string) $value) {
                    'public'  => 'public',
                    'service' => 'service',
                    default   => 'protected',
                };
            }
            $current = $current->getParentClass();
        }

        return 'protected';
    }

    /**
     * Collect field metadata, deduplicated by name, from two sources (same
     * convention as the hydrator and PayloadMetadataFactory):
     *   1. Setter methods `set{Foo}(Type $foo)` — covers protected/private DTO props.
     *   2. Public properties — for DTOs exposing fields directly.
     *
     * `required = !nullable && !hasDefault` (Phase 0 design §3.2). The
     * `filter` flag (driven by the `#[LiveFilterParam]` marker) is added per
     * field once that marker class is loadable, reporting `true` for fields that
     * carry it and `false` otherwise; it is omitted entirely if the marker class
     * is absent — forward-compatible either way.
     *
     * @return list<array<string, mixed>>
     */
    private static function collectFields(ReflectionClass $ref): array
    {
        $result = [];
        $seen = [];
        $liveFilterEnabled = self::liveFilterParamExists();

        // 1. Setters: setFoo(Type $foo) → field 'foo'
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if (!str_starts_with($name, 'set') || strlen($name) <= 3) {
                continue;
            }
            if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 1) {
                continue;
            }
            $params = $method->getParameters();
            if (count($params) !== 1) {
                continue;
            }

            $param = $params[0];
            $type = $param->getType();
            if (!$type instanceof ReflectionNamedType) {
                continue;
            }

            $fieldName = lcfirst(substr($name, 3));
            if (isset($seen[$fieldName])) {
                continue;
            }
            $seen[$fieldName] = true;

            $hasDefault = false;
            if ($ref->hasProperty($fieldName)) {
                $hasDefault = $ref->getProperty($fieldName)->hasDefaultValue();
            } elseif ($param->isOptional()) {
                $hasDefault = true;
            }

            $nullable = $type->allowsNull();
            $field = [
                'name'     => $fieldName,
                'type'     => $type->getName(),
                'nullable' => $nullable,
                'required' => !$nullable && !$hasDefault,
            ];
            if ($liveFilterEnabled) {
                $field['filter'] = self::propertyIsLiveFilter($ref, $fieldName);
            }
            $result[] = $field;
        }

        // 2. Public properties not already discovered via setters.
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic() || isset($seen[$prop->getName()])) {
                continue;
            }
            $type = $prop->getType();
            if (!$type instanceof ReflectionNamedType) {
                continue;
            }
            $seen[$prop->getName()] = true;

            $nullable = $type->allowsNull();
            $hasDefault = $prop->hasDefaultValue();
            $field = [
                'name'     => $prop->getName(),
                'type'     => $type->getName(),
                'nullable' => $nullable,
                'required' => !$nullable && !$hasDefault,
            ];
            if ($liveFilterEnabled) {
                $field['filter'] = self::propertyIsLiveFilter($ref, $prop->getName());
            }
            $result[] = $field;
        }

        return $result;
    }

    /**
     * Whether the `#[LiveFilterParam]` marker class is loadable. The
     * `modes`/`fields` derivations guard on this so the reflector never hard
     * depends on the marker's presence.
     */
    private static function liveFilterParamExists(): bool
    {
        return class_exists(self::LIVE_FILTER_PARAM_ATTRIBUTE);
    }

    private static function hasLiveFilterParam(ReflectionClass $ref): bool
    {
        if (!self::liveFilterParamExists()) {
            return false;
        }

        foreach ($ref->getProperties() as $prop) {
            if ($prop->getAttributes(self::LIVE_FILTER_PARAM_ATTRIBUTE) !== []) {
                return true;
            }
        }

        return false;
    }

    private static function propertyIsLiveFilter(ReflectionClass $ref, string $propertyName): bool
    {
        if (!self::liveFilterParamExists() || !$ref->hasProperty($propertyName)) {
            return false;
        }

        return $ref->getProperty($propertyName)->getAttributes(self::LIVE_FILTER_PARAM_ATTRIBUTE) !== [];
    }
}
