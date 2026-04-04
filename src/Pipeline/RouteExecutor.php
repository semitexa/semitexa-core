<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

use Semitexa\Core\Request;
use Semitexa\Core\Response;
use Semitexa\Core\Http\RequestDtoHydrator;
use Semitexa\Core\Http\PayloadValidator;
use Semitexa\Core\Http\Response\GenericResponse;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\DefaultRouteMetadataResolver;
use Semitexa\Core\Discovery\ResolvedRouteMetadata;
use Semitexa\Core\Http\PayloadDtoFactory;
use Semitexa\Core\Http\ContentNegotiator;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Contract\ExceptionResponseMapperInterface;
use Semitexa\Core\Contract\RouteResponseDecoratorInterface;
use Semitexa\Core\Contract\RouteMetadataResolverInterface;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Container\RequestScopedContainer;

class RouteExecutor
{
    public function __construct(
        private readonly RequestScopedContainer $requestScopedContainer,
        private readonly ContainerInterface $container,
        /** @var \Semitexa\Auth\AuthBootstrapper|null */
        private readonly ?object $authBootstrapper = null,
    ) {}

    /**
     * @param array{type?: string, class?: string, handlers?: list<array{class?: string, execution?: string}>, responseClass?: string, method?: string, name?: string, consumes?: list<string>|null, produces?: list<string>|null} $route
     */
    public function execute(array $route, Request $request): Response
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
                return $this->decorateResponse(Response::json([
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

            // 5. Render Response
            $renderer = new ResponseRenderer();
            $resDto = $renderer->render($resDto, $reqDto, $request, $route);

            // 6. Adapt to Core Response
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
    private function resolveRouteMetadata(array $route): ResolvedRouteMetadata
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
     */
    private function resolveExceptionMapper(): ExceptionResponseMapperInterface
    {
        if ($this->container->has(ExceptionResponseMapperInterface::class)) {
            /** @var ExceptionResponseMapperInterface $mapper */
            $mapper = $this->container->get(ExceptionResponseMapperInterface::class);
            return $mapper;
        }

        return new ExceptionMapper();
    }

    private function decorateResponse(Response $response, Request $request, ResolvedRouteMetadata $metadata): Response
    {
        if ($this->container->has(RouteResponseDecoratorInterface::class)) {
            /** @var RouteResponseDecoratorInterface $decorator */
            $decorator = $this->container->get(RouteResponseDecoratorInterface::class);
            return $decorator->decorate($response, $request, $metadata);
        }

        return (new DefaultRouteResponseDecorator())->decorate($response, $request, $metadata);
    }

    /**
     * @return array{0: object, 1: ?Response}
     */
    private function hydrateRequest(array $route, Request $request): array
    {
        $requestClass = $route['class'] ?? null;
        if ($requestClass === null) {
            throw new \RuntimeException('Route has no class defined');
        }

        $traits = AttributeDiscovery::getPayloadPartsForClass($requestClass);
        $reqDto = class_exists($requestClass) ? PayloadDtoFactory::createInstance($requestClass, $traits) : null;
        if (!$reqDto) {
            throw new \RuntimeException("Cannot instantiate request class: {$requestClass}");
        }

        try {
            $reqDto = RequestDtoHydrator::hydrate($reqDto, $request);
            if (method_exists($reqDto, 'setHttpRequest')) {
                $reqDto->setHttpRequest($request);
            }
        } catch (\Semitexa\Core\Http\Exception\TypeMismatchException $e) {
            return [$reqDto, Response::json(['errors' => [$e->field => [$e->getMessage()]]], HttpStatus::UnprocessableEntity->value)];
        } catch (\Throwable $e) {
            return [$reqDto, Response::json(['errors' => ['_body' => ['Request body could not be processed: ' . $e->getMessage()]]], HttpStatus::UnprocessableEntity->value)];
        }

        $validationResult = PayloadValidator::validate($reqDto, $request);
        if (!$validationResult->isValid()) {
            return [$reqDto, Response::json(['errors' => $validationResult->getErrors()], HttpStatus::UnprocessableEntity->value)];
        }

        return [$reqDto, null];
    }

    private function resolveResponseDto(array $route): object
    {
        $responseClass = $route['responseClass'] ?? null;
        if ($responseClass !== null && !class_exists($responseClass)) {
            throw new \RuntimeException("Cannot instantiate response class: {$responseClass}");
        }
        if ($responseClass !== null) {
            $traits = AttributeDiscovery::getResourcePartsForClass($responseClass);
            $resDto = PayloadDtoFactory::createInstance($responseClass, $traits);
        } else {
            $resDto = null;
        }

        if ($resDto === null) {
            $resDto = new GenericResponse();
        }

        // Apply AsResource defaults from resolved attributes
        $resolvedResponse = AttributeDiscovery::getResolvedResponseAttributes($responseClass ?? get_class($resDto));
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

    private function adaptResponse(object $resDto): Response
    {
        if ($resDto instanceof Response) {
            return $resDto;
        }
        if (method_exists($resDto, 'toCoreResponse')) {
            return $resDto->toCoreResponse();
        }
        return Response::json(['ok' => true]);
    }
}
