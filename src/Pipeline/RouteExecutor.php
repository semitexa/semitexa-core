<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

use Semitexa\Core\Request;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\Http\PayloadHydrator;
use Semitexa\Core\Http\PayloadValidator;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Discovery\DefaultRouteMetadataResolver;
use Semitexa\Core\Discovery\PayloadPartRegistry;
use Semitexa\Core\Discovery\ResolvedRouteMetadata;
use Semitexa\Core\Environment;
use Semitexa\Core\Error\ErrorRouteDispatcher;
use Semitexa\Core\Http\PayloadFactory;
use Semitexa\Core\Http\ContentNegotiator;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Exception\PipelineException;
use Semitexa\Core\Auth\AuthBootstrapperInterface;
use Semitexa\Core\Contract\ExceptionResponseMapperInterface;
use Semitexa\Core\Contract\RouteResponseDecoratorInterface;
use Semitexa\Core\Contract\RouteMetadataResolverInterface;
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

            // 1. Hydrate and Validate
            [$reqDto, $validationResponse] = $this->hydrateRequest($route, $request);
            if ($validationResponse) {
                return $this->decorateResponse($validationResponse, $request, $metadata);
            }

            // 2. Resolve Response DTO
            $resDto = $this->resolveResponseDto($route);

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
        if (method_exists($mapper, 'withErrorRouteDispatcher')) {
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
     * @return array{0: object, 1: ?HttpResponse}
     */
    private function hydrateRequest(DiscoveredRoute $route, Request $request): array
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

        try {
            $reqDto = PayloadHydrator::hydrate($reqDto, $request);
            if (method_exists($reqDto, 'setHttpRequest')) {
                $reqDto->setHttpRequest($request);
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

        $validationResult = PayloadValidator::validate($reqDto, $request);
        if (!$validationResult->isValid()) {
            return [$reqDto, HttpResponse::json(['errors' => $validationResult->getErrors()], HttpStatus::UnprocessableEntity->value)];
        }

        return [$reqDto, null];
    }

    private function resolveResponseDto(DiscoveredRoute $route): object
    {
        $responseClass = $route->responseClass;
        if ($responseClass !== null && !class_exists($responseClass)) {
            throw new PipelineException("Cannot instantiate response class: {$responseClass}");
        }
        if ($responseClass !== null) {
            $traits = $this->getPayloadPartRegistry()->getResourcePartsForClass($responseClass);
            $resDto = PayloadFactory::createInstance($responseClass, $traits);
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
