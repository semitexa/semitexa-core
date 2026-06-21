<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

use Semitexa\Core\Request;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\Http\PayloadHydrator;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Discovery\DefaultRouteMetadataResolver;
use Semitexa\Core\Discovery\PayloadPartRegistry;
use Semitexa\Core\Discovery\ResolvedRouteMetadata;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\Environment;
use Semitexa\Core\Error\ErrorRouteDispatcher;
use Semitexa\Core\Http\PayloadFactory;
use Semitexa\Core\Http\ContentNegotiator;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Exception\PipelineException;
use Semitexa\Core\Exception\AccessDeniedException;
use Semitexa\Core\Exception\AuthenticationException;
use Semitexa\Core\Pipeline\ReRun\ReRunResult;
use Semitexa\Core\Lifecycle\SessionPhase;
use Semitexa\Core\Lifecycle\RequestLifecycleContext;
use Semitexa\Core\Log\FallbackErrorLogger;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Core\Tenant\TenancyBootstrapperInterface;
use Semitexa\Core\Auth\AuthBootstrapperInterface;
use Semitexa\Core\Contract\ExceptionResponseMapperInterface;
use Semitexa\Core\Contract\RouteResponseDecoratorInterface;
use Semitexa\Core\Contract\RouteMetadataResolverInterface;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Exception\ValidationException;
use Semitexa\Core\Container\PropertyInjector;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Container\RequestScopedContainer;

class RouteExecutor
{
    private ?ErrorRouteDispatcher $errorRouteDispatcher = null;

    public function __construct(
        private readonly RequestScopedContainer $requestScopedContainer,
        private readonly ContainerInterface $container,
        private readonly ?AuthBootstrapperInterface $authBootstrapper = null,
    ) {}

    private function getAttributeDiscovery(): AttributeDiscovery
    {
        /** @var AttributeDiscovery $attributeDiscovery */
        $attributeDiscovery = $this->container->get(AttributeDiscovery::class);

        return $attributeDiscovery;
    }

    private function getPayloadPartRegistry(): PayloadPartRegistry
    {
        if ($this->container->has(PayloadPartRegistry::class)) {
            /** @var PayloadPartRegistry $registry */
            $registry = $this->container->get(PayloadPartRegistry::class);
            return $registry;
        }

        // Fallback: extract from AttributeDiscovery (backwards compat)
        return $this->getAttributeDiscovery()->getPayloadPartRegistry();
    }

    public function execute(DiscoveredRoute $route, Request $request): HttpResponse
    {
        $metadata = null;
        $exceptionMapper = null;

        try {
            $metadata = $this->resolveRouteMetadata($route);
            $exceptionMapper = $this->resolveExceptionMapper();

            // 0. Reject unsupported Content-Type early
            $consumesResult = ContentNegotiator::checkConsumes(
                $metadata->consumes,
                $request
            );
            if ($consumesResult !== true) {
                return $this->decorateResponse(HttpResponse::json([
                    'error' => 'Unsupported Media Type',
                    'message' => "Content-Type '{$consumesResult}' is not supported.",
                    'supported' => $metadata->consumes,
                ], HttpStatus::UnsupportedMediaType->value), $request, $metadata);
            }

            // Multi-Modal API — Mode 4: an OPTIONS request to a payload route is
            // a metadata probe, not an invocation. It runs the SAME pipeline
            // (same payload, same auth) but skips body hydration/validation and
            // is served by the generic OptionsMetadataHandler (see below).
            $isOptions = strtoupper($request->getMethod()) === 'OPTIONS';

            // 1a. Create a bare payload instance (no request data yet).
            $reqDto = $this->createBarePayload($route);

            // 1b. Pre-hydration auth gate. When an authorization layer registers
            //     a PreHydrationAuthGateInterface, it runs here so that protected
            //     routes reject unauthenticated requests BEFORE hydration or
            //     validation touches the request body. Public routes are a no-op.
            //     OPTIONS is gated identically — its access model is inherited,
            //     not bypassed.
            if ($this->container->has(PreHydrationAuthGateInterface::class)) {
                /** @var PreHydrationAuthGateInterface $gate */
                $gate = $this->container->get(PreHydrationAuthGateInterface::class);
                $gate->gate($reqDto, $request, $this->authBootstrapper);
            }

            // 1c. Hydrate and Validate — skipped for OPTIONS. The endpoint is
            //     only being described, so the request body is irrelevant and a
            //     payload's ValidatablePayloadInterface::validate() business
            //     rules must not reject an (empty) OPTIONS probe. OPTIONS reports
            //     type-level shape only; validate() is not reflected.
            if (!$isOptions) {
                [$reqDto, $validationResponse] = $this->fillAndValidatePayload($reqDto, $request);
                if ($validationResponse) {
                    return $this->decorateResponse($validationResponse, $request, $metadata);
                }
            }

            // For OPTIONS, swap the resolved route for a variant whose sole
            // handler is the generic OptionsMetadataHandler and whose response
            // class is dropped (the handler writes the metadata JSON directly).
            // requestClass + accessType are preserved so the AuthCheck phase
            // still enforces this endpoint's own access model.
            if ($isOptions) {
                $route = $this->buildOptionsRoute($route);
            }

            // 2. Resolve Response DTO (Accept-driven for multi-profile routes)
            $resDto = $this->resolveResponseDto($route, $request);

            // 3. Build Context
            $context = new RequestPipelineContext(
                requestDto: $reqDto,
                route: $route,
                request: $request,
                resourceDto: $resDto,
                authBootstrapper: $this->authBootstrapper,
                resolvedMetadata: $metadata,
            );

            // 4. Execute Pipeline
            $pipelineExecutor = new PipelineExecutor($this->requestScopedContainer, $this->container);
            $pipelineExecutor->execute($context);
            $resDto = $context->resourceDto;
            if (!is_object($resDto)) {
                throw new PipelineException('Pipeline did not produce a response DTO.');
            }

            // 5. Render Response
            $renderer = new ResponseRenderer();
            $resDto = $renderer->render($resDto, $reqDto, $request, $route);

            // 6. Adapt to HttpResponse
            return $this->decorateResponse($this->adaptResponse($resDto), $request, $metadata);

        } catch (\Semitexa\Core\Exception\NotFoundException $e) {
            // Let NotFoundException bubble up so Application::handleRouteException()
            // can dispatch the custom error.404 route when registered.
            throw $e;
        } catch (DomainException|\Throwable $e) {
            if ($exceptionMapper === null || $metadata === null) {
                throw $e;
            }
            return $this->decorateResponse($exceptionMapper->map($e, $request, $metadata), $request, $metadata);
        }
    }

    /**
     * Re-run the full handler chain for an already-hydrated, cached request DTO
     * (Track R · R2; design phase2 §B.3, track-r §B.2/§B.4). This is the heart of
     * Shape 1 "re-run self-authorization": a frozen authorized request is replayed
     * on demand, AUTH-FIRST, re-querying data under the recipient's *current*
     * authorization, and either yields a freshly-resolved frame or TERMINATEs.
     *
     * It is {@see execute()} minus `createBarePayload()` + `fillAndValidatePayload()`
     * (the DTO is already hydrated and validated — unchanged input is not re-parsed),
     * but it KEEPS, in order:
     *   1. the pre-hydration auth gate — fed the cached DTO, run BEFORE any data
     *      resolution. Identity is resolved from $request (the rebuilt live session),
     *      NEVER from $cachedDto (the anti-poisoning invariant);
     *   2. a fresh {@see resolveResponseDto()} — a new Resource instance per run;
     *   3. {@see PipelineExecutor::execute()} (AuthCheck → HandleRequest) — so the
     *      AuthCheck-phase authorization listener re-authorizes the re-established
     *      subject every run, before the route handlers (the data resolvers) run.
     *
     * A de-authorization on this tick — the pre-hydration gate or the AuthCheck phase
     * throwing {@see AuthenticationException} / {@see AccessDeniedException} (logout,
     * identity change, permission revoke) — short-circuits to TERMINATE: NO data
     * frame is produced for a subject that just lost access, and because the gate
     * runs first and the handlers run last, the data resolvers are never reached
     * after a denial.
     *
     * Headless by construction: no HTTP, no SSE loop, no connect coordinator — the
     * caller ({@see \Semitexa\Core\Pipeline\ReRun\ReRunnerInterface}) re-establishes
     * the immutable tenant block and hands in the rebuilt request + cached DTO; the
     * request-scoped execution context (Session / CookieJar / Request trio + live
     * tenant/auth/locale) is re-established HERE via {@see establishReRunExecutionContext()},
     * after the auth gate and before the pipeline, so execution-scoped pipeline
     * listeners resolve (Track R · R8c-2 / Gap A).
     */
    public function reExecute(DiscoveredRoute $route, Request $request, object $cachedDto, array $filterOverride = []): ReRunResult
    {
        try {
            $metadata = $this->resolveRouteMetadata($route);

            // 1. Pre-hydration auth gate — AUTH-FIRST, before any data resolution.
            //    Fed the cached DTO for attribute resolution only; the subject is
            //    resolved from $request (the live session), never from the DTO.
            if ($this->container->has(PreHydrationAuthGateInterface::class)) {
                /** @var PreHydrationAuthGateInterface $gate */
                $gate = $this->container->get(PreHydrationAuthGateInterface::class);
                $gate->gate($cachedDto, $request, $this->authBootstrapper);
            }

            // 1b. Re-establish the execution context (Track R · R8c-2 / Gap A).
            //     The held-open re-run skips the normal lifecycle SessionPhase, so the
            //     request-scoped trio (Request / Session / CookieJar) is never set into
            //     the RequestScopedContainer and `isExecutionContextReady()` stays
            //     false — any execution-scoped pipeline listener resolved during the
            //     AuthCheck/HandleRequest phases (e.g. ResetPlatformUiSseSessionListener)
            //     then throws ExecutionContextNotReadyException and the whole re-run
            //     aborts with no frame. Run SessionPhase's establish-only path here —
            //     AFTER the auth gate (so the session it loads carries the freshly
            //     re-resolved live subject, never the cached DTO) and BEFORE the
            //     pipeline. SessionPhase::finalize() (session persist + Set-Cookie) is
            //     intentionally NOT run: a re-run tick is headless and must not mutate
            //     the stored session or emit cookies.
            $this->establishReRunExecutionContext($request);

            // 1c. FILTER-ONLY view-change override (Intended Grid Model).
            //     A view-change command's new view params are merged onto the cached
            //     DTO HERE — AFTER the auth gate (step 1) and the execution-context
            //     re-establishment (step 1b) have already re-resolved identity from
            //     the live session, so the override can never influence WHO the
            //     re-run authorizes as. The merge is structurally filter-only: only
            //     fields the DTO marks #[LiveFilterParam] are writable; identity /
            //     session / tenant fields are unmarked and therefore un-overridable
            //     by construction (the R2 anti-poisoning invariant). Empty override
            //     (a mutation-driven re-run) is a no-op — the cached DTO is used
            //     verbatim, byte-identical to before view-change commands existed.
            if ($filterOverride !== []) {
                $applied = ReRun\LiveFilterParamOverride::apply($cachedDto, $filterOverride);
                FallbackErrorLogger::log('Track R view-change filter override applied', [
                    'path' => $request->getPath(),
                    'applied' => $applied['applied'],
                    'ignored' => $applied['ignored'],
                ]);
            }

            // 2. Fresh response DTO (a new Resource instance per run). Hydration and
            //    validation are intentionally skipped — the cached DTO already holds
            //    the unchanged, validated request shape.
            $resDto = $this->resolveResponseDto($route, $request);

            // 3. Build context with the cached request DTO + fresh resource DTO.
            $context = new RequestPipelineContext(
                requestDto: $cachedDto,
                route: $route,
                request: $request,
                resourceDto: $resDto,
                authBootstrapper: $this->authBootstrapper,
                resolvedMetadata: $metadata,
            );

            // 4. Execute the pipeline (AuthCheck → HandleRequest). The AuthCheck
            //    phase re-authorizes the re-established subject before the route
            //    handlers (data resolvers) run.
            $pipelineExecutor = new PipelineExecutor($this->requestScopedContainer, $this->container);
            $pipelineExecutor->execute($context);
            $resDto = $context->resourceDto;
            if (!is_object($resDto)) {
                throw new PipelineException('Re-run pipeline did not produce a response DTO.');
            }

            // 5. Render the freshly-resolved Resource into the frame body.
            $renderer = new ResponseRenderer();
            $resDto = $renderer->render($resDto, $cachedDto, $request, $route);

            return ReRunResult::frame($this->adaptResponse($resDto));
        } catch (AuthenticationException|AccessDeniedException $e) {
            // The subject is no longer authorized on this tick (logout / identity
            // change / permission revoke). Terminate the stream — no data frame.
            return ReRunResult::terminate($e->getMessage());
        }
    }

    /**
     * Establish the execution context for a re-run tick by running SessionPhase's
     * establish-only path (its `execute()`, NOT `finalize()`) against the rebuilt
     * request. SessionPhase sets the request-scoped trio (Session / CookieJar /
     * Request) — which flips {@see RequestScopedContainer::isExecutionContextReady()}
     * to true — plus the live tenant / auth / locale contexts (auth resolved fresh
     * from the session, never the cached DTO; this runs AFTER the pre-hydration auth
     * gate has already re-resolved the live subject). Without it, execution-scoped
     * pipeline listeners throw {@see \Semitexa\Core\Container\Exception\ExecutionContextNotReadyException}.
     *
     * Fail-soft by design: when the container cannot supply a tenant store (a minimal
     * / no-tenancy / unit container) or SessionPhase cannot build a session handler,
     * establishment is skipped and logged rather than thrown — the re-run then behaves
     * exactly as it did before this method existed (it may still trip an
     * execution-scoped listener, but is never made WORSE), so this can never turn a
     * working re-run into a failing one. Production (a real session handler +
     * DefaultTenantContextStore bound) takes the success path.
     */
    private function establishReRunExecutionContext(Request $request): void
    {
        if (!$this->container->has(TenantContextStoreInterface::class)) {
            return;
        }

        try {
            /** @var TenantContextStoreInterface $tenantStore */
            $tenantStore = $this->container->get(TenantContextStoreInterface::class);
            $tenancy = $this->container->has(TenancyBootstrapperInterface::class)
                ? $this->container->get(TenancyBootstrapperInterface::class)
                : null;

            $sessionPhase = new SessionPhase(
                $this->container,
                $this->requestScopedContainer,
                $tenantStore,
                $tenancy instanceof TenancyBootstrapperInterface ? $tenancy : null,
            );
            // execute() only — NEVER finalize() (no session persist, no Set-Cookie).
            $sessionPhase->execute(new RequestLifecycleContext($request));
        } catch (\Throwable $e) {
            FallbackErrorLogger::log('Track R re-run execution-context establishment failed', [
                'path' => $request->getPath(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve route metadata through the registered RouteMetadataResolverInterface.
     * Falls back to the DefaultRouteMetadataResolver when the container has no binding.
     */
    private function resolveRouteMetadata(DiscoveredRoute $route): ResolvedRouteMetadata
    {
        if ($this->container->has(RouteMetadataResolverInterface::class)) {
            /** @var RouteMetadataResolverInterface $resolver */
            $resolver = $this->container->get(RouteMetadataResolverInterface::class);
            return $resolver->resolve($route);
        }

        return (new DefaultRouteMetadataResolver())->resolve($route);
    }

    /**
     * Resolve the exception mapper through the container.
     * Falls back to a bare ExceptionMapper when the container has no binding.
     *
     * Core interacts only with ExceptionResponseMapperInterface. The request-scoped
     * ErrorRouteDispatcher is handed to the mapper through the contract seam; any
     * decorator in a downstream package is responsible for forwarding the dispatcher
     * into whatever it wraps.
     */
    private function resolveExceptionMapper(): ExceptionResponseMapperInterface
    {
        $mapper = $this->container->has(ExceptionResponseMapperInterface::class)
            ? $this->container->get(ExceptionResponseMapperInterface::class)
            : new ExceptionMapper();

        /** @var ExceptionResponseMapperInterface $mapper */
        if (is_callable([$mapper, 'withErrorRouteDispatcher'])) {
            $configuredMapper = $mapper->withErrorRouteDispatcher($this->getErrorRouteDispatcher());

            if ($configuredMapper instanceof ExceptionResponseMapperInterface) {
                return $configuredMapper;
            }
        }

        return $mapper;
    }

    private function getErrorRouteDispatcher(): ErrorRouteDispatcher
    {
        if ($this->errorRouteDispatcher !== null) {
            return $this->errorRouteDispatcher;
        }

        /** @var Environment $environment */
        $environment = $this->container->has(Environment::class)
            ? $this->container->get(Environment::class)
            : Environment::create();

        /** @var \Semitexa\Core\Discovery\RouteRegistry $routeRegistry */
        $routeRegistry = $this->container->get(\Semitexa\Core\Discovery\RouteRegistry::class);
        $this->errorRouteDispatcher = new ErrorRouteDispatcher(
            $routeRegistry,
            $this->requestScopedContainer,
            $this->container,
            $this->authBootstrapper,
            $environment,
        );

        return $this->errorRouteDispatcher;
    }

    private function decorateResponse(HttpResponse $response, Request $request, ResolvedRouteMetadata $metadata): HttpResponse
    {
        if ($this->container->has(RouteResponseDecoratorInterface::class)) {
            /** @var RouteResponseDecoratorInterface $decorator */
            $decorator = $this->container->get(RouteResponseDecoratorInterface::class);
            return $decorator->decorate($response, $request, $metadata);
        }

        return (new DefaultRouteResponseDecorator())->decorate($response, $request, $metadata);
    }

    /**
     * Build the OPTIONS variant of a payload route (Multi-Modal API — Mode 4).
     *
     * Keeps the route's identity — `path`, `requestClass`, `accessType`,
     * tenant scopes — so auth and routing behave exactly as the endpoint's
     * other methods, but replaces the handler list with the single generic
     * {@see OptionsMetadataHandler} and drops the response class + any
     * multi-profile dispatch (the handler emits the metadata JSON itself).
     */
    private function buildOptionsRoute(DiscoveredRoute $route): DiscoveredRoute
    {
        return new DiscoveredRoute(
            path: $route->path,
            methods: $route->methods,
            name: $route->name,
            requestClass: $route->requestClass,
            responseClass: null,
            // 'execution' omitted → defaults to Sync in PipelineExecutor.
            handlers: [['class' => OptionsMetadataHandler::class]],
            type: $route->type,
            transport: $route->transport,
            produces: $route->produces,
            consumes: $route->consumes,
            module: $route->module,
            requirements: $route->requirements,
            defaults: $route->defaults,
            options: $route->options,
            tags: $route->tags,
            accessType: $route->accessType,
            tenantScopes: $route->tenantScopes,
            renderProfile: null,
            responsesByProfile: null,
        );
    }

    /**
     * Build a bare payload instance (no request data) suitable for attribute
     * resolution. Used by the pre-hydration auth gate before hydration.
     */
    private function createBarePayload(DiscoveredRoute $route): object
    {
        $requestClass = $route->requestClass;
        if ($requestClass === '') {
            throw new PipelineException('Route has no class defined');
        }

        $traits = $this->getPayloadPartRegistry()->getPayloadPartsForClass($requestClass);
        $reqDto = class_exists($requestClass) ? PayloadFactory::createInstance($requestClass, $traits) : null;
        if (!$reqDto) {
            throw new PipelineException("Cannot instantiate request class: {$requestClass}");
        }

        PropertyInjector::inject($reqDto, $this->container);

        return $reqDto;
    }

    /**
     * Fill the bare payload from the request and run validation. The payload
     * instance is returned in both success and failure cases so validation
     * errors can reference the class the request was routed to.
     *
     * @return array{0: object, 1: ?HttpResponse}
     */
    private function fillAndValidatePayload(object $reqDto, Request $request): array
    {
        try {
            $reqDto = PayloadHydrator::hydrate($reqDto, $request);
            if (method_exists($reqDto, 'setHttpRequest')) {
                $reqDto->setHttpRequest($request);
            }
            // Cross-field validation hook — fires once, after every setter
            // has run, before any route handler. Payloads opt in by
            // implementing ValidatablePayloadInterface; everything else
            // skips this step entirely.
            if ($reqDto instanceof ValidatablePayloadInterface) {
                $errors = $reqDto->validate();
                if ($errors !== []) {
                    throw new ValidationException($errors);
                }
            }
        } catch (\Semitexa\Core\Exception\ValidationException $e) {
            return [$reqDto, HttpResponse::json(['errors' => $e->getErrorContext()['errors']], HttpStatus::UnprocessableEntity->value)];
        } catch (\Semitexa\Core\Http\Exception\TypeMismatchException $e) {
            return [$reqDto, HttpResponse::json(['errors' => [$e->field => [$e->getMessage()]]], HttpStatus::UnprocessableEntity->value)];
        } catch (\Throwable $e) {
            // Security: suppress exception messages in production (VULN-007)
            // Only expose details in debug mode for development
            $httpRequest = method_exists($reqDto, 'getHttpRequest') ? $reqDto->getHttpRequest() : null;
            $message = $httpRequest instanceof Request && self::isDebugMode($httpRequest)
                ? $e->getMessage()
                : 'Request body could not be processed';
            return [$reqDto, HttpResponse::json(['errors' => ['_body' => [$message]]], HttpStatus::UnprocessableEntity->value)];
        }

        return [$reqDto, null];
    }

    /**
     * @return list<\Semitexa\Core\Resource\RenderProfile>
     */
    private function normalizeDeclaredProfiles(mixed $renderProfile): array
    {
        if ($renderProfile instanceof \Semitexa\Core\Resource\RenderProfile) {
            return [$renderProfile];
        }
        if (is_array($renderProfile)) {
            $profiles = [];
            foreach ($renderProfile as $entry) {
                if ($entry instanceof \Semitexa\Core\Resource\RenderProfile) {
                    $profiles[] = $entry;
                }
            }
            return $profiles;
        }
        return [];
    }

    private function resolveResponseDto(DiscoveredRoute &$route, ?Request $request = null): object
    {
        $responseClass = $route->responseClass;

        // When the route declares `responsesByProfile`, the
        // CrossProfileDispatcher picks the response class from the request's
        // Accept header. Single-profile routes keep the legacy path.
        if ($route->responsesByProfile !== null && $route->responsesByProfile !== []) {
            $declaredProfiles = $this->normalizeDeclaredProfiles($route->renderProfile);
            if ($declaredProfiles !== []) {
                $accept = $request !== null ? $request->getHeader('accept') : null;
                /** @var \Semitexa\Core\Resource\CrossProfileDispatcher $dispatcher */
                $dispatcher = $this->container->get(\Semitexa\Core\Resource\CrossProfileDispatcher::class);
                $responseClass = $dispatcher->resolveResponseClass(
                    declaredProfiles:    $declaredProfiles,
                    responsesByProfile:  $route->responsesByProfile,
                    acceptHeader:        $accept,
                    routeContext:        $route->path,
                );

                if ($responseClass !== null && $responseClass !== $route->responseClass) {
                    /** @var RouteRegistry $routeRegistry */
                    $routeRegistry = $this->container->get(RouteRegistry::class);
                    /** @var \Semitexa\Core\Discovery\HandlerRegistry|null $handlerRegistry */
                    $handlerRegistry = $this->container->has(\Semitexa\Core\Discovery\HandlerRegistry::class)
                        ? $this->container->get(\Semitexa\Core\Discovery\HandlerRegistry::class)
                        : null;
                    $route = $routeRegistry->rebindHandlersForResponse($route, $responseClass, $handlerRegistry);
                }
            }
        }

        if ($responseClass !== null && !class_exists($responseClass)) {
            throw new PipelineException("Cannot instantiate response class: {$responseClass}");
        }
        if ($responseClass !== null) {
            $traits = $this->getPayloadPartRegistry()->getResourcePartsForClass($responseClass);
            $resDto = PayloadFactory::createInstance($responseClass, $traits);
            PropertyInjector::inject($resDto, $this->container);
        } else {
            $resDto = null;
        }

        if ($resDto === null) {
            $resDto = new ResourceResponse();
        }

        // Apply AsResource defaults from resolved attributes
        $resolvedResponse = $this->getAttributeDiscovery()->getResolvedResponseAttributes($responseClass ?? get_class($resDto));
        if ($resolvedResponse) {
            if (isset($resolvedResponse['handle']) && $resolvedResponse['handle'] && method_exists($resDto, 'setRenderHandle')) {
                $resDto->setRenderHandle($resolvedResponse['handle']);
            }
            if (isset($resolvedResponse['context']) && method_exists($resDto, 'setRenderContext')) {
                $resDto->setRenderContext($resolvedResponse['context']);
            }
            if (array_key_exists('format', $resolvedResponse) && method_exists($resDto, 'setRenderFormat')) {
                $resDto->setRenderFormat($resolvedResponse['format']);
            }
            if (isset($resolvedResponse['renderer']) && method_exists($resDto, 'setRendererClass')) {
                $resDto->setRendererClass($resolvedResponse['renderer']);
            }
            if (isset($resolvedResponse['template']) && method_exists($resDto, 'setDeclaredTemplate')) {
                $resDto->setDeclaredTemplate($resolvedResponse['template']);
            }
        }

        return $resDto;
    }

    private function adaptResponse(object $resDto): HttpResponse
    {
        if ($resDto instanceof HttpResponse) {
            return $resDto;
        }
        if (method_exists($resDto, 'toCoreResponse')) {
            $response = $resDto->toCoreResponse();
            if ($response instanceof HttpResponse) {
                return $response;
            }

            throw new PipelineException('toCoreResponse() must return an instance of HttpResponse.');
        }
        return HttpResponse::json(['ok' => true]);
    }

    /**
     * Check if debug mode is enabled via the application environment configuration.
     */
    private static function isDebugMode(?Request $_request): bool
    {
        $debug = \Semitexa\Core\Environment::create()->appDebug;
        return filter_var($debug, FILTER_VALIDATE_BOOL);
    }
}
