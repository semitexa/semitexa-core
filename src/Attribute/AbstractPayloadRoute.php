<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Resource\RenderProfile;

/**
 * Abstract carrier of every routable-payload metadata field.
 *
 * The three concrete payload-route attributes — AsPublicPayload,
 * AsProtectedPayload, AsServicePayload — live in semitexa-authorization and
 * extend this class. Each fixes the access classification (public/protected/
 * service) via {@see getAccessType()}; route metadata fields are identical
 * across all three so payload migration is mechanical.
 *
 * This class is intentionally NOT itself an #[Attribute]: it exists only to
 * hold the shared constructor + accessor surface and to give framework
 * discovery (AttributeDiscovery, PayloadHydrator, project-graph extractors,
 * OpenAPI schema generator, semitexa-testing PayloadMetadataFactory) a single
 * IS_INSTANCEOF target so each integrator does not have to know the names of
 * the three concrete attribute classes — preserving the dependency direction
 * semitexa-core → no upward dependency on semitexa-authorization.
 *
 * Environment-variable references via the `env::VAR_NAME::default` syntax in
 * any constructor argument are resolved by AttributeDiscovery via
 * EnvValueResolver, identical to the legacy AsPayload contract.
 */
abstract class AbstractPayloadRoute
{
    public readonly ?string $doc;

    public function __construct(
        ?string $doc = null,
        public ?string $base = null,
        /** Class name of the Request this one overrides (strict chain: only current head can be overridden). */
        public ?string $overrides = null,
        public ?string $path = null,
        /** @var list<string>|null */
        public ?array $methods = null,
        public ?string $name = null,
        /** @var array<string, mixed>|null */
        public ?array $requirements = null,
        /** @var array<string, mixed>|null */
        public ?array $defaults = null,
        /** @var array<string, mixed>|null */
        public ?array $options = null,
        /** @var array<string>|null */
        public ?array $tags = null,
        public ?string $responseWith = null,
        /** @var list<string>|null */
        public ?array $consumes = null,
        /** @var list<string>|null */
        public ?array $produces = null,
        public ?TransportType $transport = null,
        /** @var RenderProfile|list<RenderProfile>|null */
        public RenderProfile|array|null $renderProfile = null,
        /** @var array<string, class-string>|null */
        public ?array $responsesByProfile = null,
        /**
         * The provable authorization-gate model for an SSE endpoint. Required
         * (non-null) whenever `transport` is {@see TransportType::Sse}: the boot
         * guard in AttributeDiscovery fails boot for an SSE route that declares
         * no gate model. Orthogonal to access + transport; read in the same
         * IS_INSTANCEOF discovery pass (same precedent as a route field, no
         * upward dependency on semitexa-authorization).
         *
         * Declared LAST so adding it never shifts the positional argument order
         * of the pre-existing `renderProfile` / `responsesByProfile` parameters.
         */
        public ?SseGateModel $sseGateModel = null,
    ) {
        $this->doc = $doc;
        if ($this->consumes !== null) {
            $this->consumes = array_map('strtolower', $this->consumes);
        }
        if ($this->produces !== null) {
            $this->produces = array_map('strtolower', $this->produces);
        }
    }

    /**
     * The single source of truth for this payload's access classification.
     * Each concrete attribute hard-codes its own case; there is no default.
     */
    abstract public function getAccessType(): PayloadAccessType;
}
