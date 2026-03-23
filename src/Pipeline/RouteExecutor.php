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
use Semitexa\Core\Http\Response\ResponseFormat;
use Semitexa\Core\Http\ContentNegotiator;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Exception\NegotiationFailedException;
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
        $metadata = $this->resolveRouteMetadata($route);
        $exceptionMapper = $this->resolveExceptionMapper();

        try {
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
            $resDto = $this->renderResponse($resDto, $reqDto, $request, $route);

            // 6. Adapt to Core Response
            return $this->decorateResponse($this->adaptResponse($resDto), $request, $metadata);

        } catch (\Semitexa\Core\Exception\NotFoundException $e) {
            // Let NotFoundException bubble up so Application::handleRouteException()
            // can dispatch the custom error.404 route when registered.
            throw $e;
        } catch (DomainException $e) {
            return $this->decorateResponse($exceptionMapper->map($e, $request, $metadata), $request, $metadata);
        } catch (\Throwable $e) {
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
        } catch (\Throwable) {
            // Continue with empty DTO if hydration fails
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

    private function renderResponse(object $resDto, ?object $reqDto, Request $request, array $route): object
    {
        // Redirect short-circuit: if the resource has a redirect URL, skip rendering
        if (method_exists($resDto, 'getRedirectUrl') && $resDto->getRedirectUrl() !== null) {
            $statusCode = method_exists($resDto, 'getStatusCode') ? $resDto->getStatusCode() : HttpStatus::Found->value;
            return Response::redirect($resDto->getRedirectUrl(), $statusCode);
        }

        $handle = method_exists($resDto, 'getRenderHandle') ? $resDto->getRenderHandle() : null;
        $context = method_exists($resDto, 'getRenderContext') ? $resDto->getRenderContext() : [];
        /** @var ResponseFormat|null $format */
        $format = method_exists($resDto, 'getRenderFormat') ? $resDto->getRenderFormat() : null;

        // No render handle: render as JSON if context is set, otherwise return as-is
        if (!$handle) {
            if ($context !== []) {
                if ($format === null || $format === ResponseFormat::Layout) {
                    $format = ResponseFormat::Json;
                }
            } else {
                return $resDto;
            }
        }
        $rendererClass = method_exists($resDto, 'getRendererClass') ? $resDto->getRendererClass() : null;

        // Negotiate format when produces is set on the route and we have a render handle
        $produces = $route['produces'] ?? null;
        if ($handle && $produces !== null && $produces !== []) {
            try {
                $defaultKey = $format !== null ? self::formatEnumToKey($format) : 'json';
                $negotiatedKey = ContentNegotiator::negotiateResponseFormat($produces, $request, $defaultKey);
                $format = self::keyToFormatEnum($negotiatedKey);
            } catch (NegotiationFailedException $e) {
                return Response::json([
                    'error' => 'Not Acceptable',
                    'message' => $e->getMessage(),
                    'available' => $e->produces,
                ], HttpStatus::NotAcceptable->value);
            }
        }

        if ($format === null) {
            $format = ResponseFormat::Layout;
        }

        return match ($format) {
            ResponseFormat::Json   => $this->renderJson($resDto, $context),
            ResponseFormat::Layout => $this->renderLayout($resDto, $reqDto, $handle, $context, $rendererClass),
            ResponseFormat::Xml    => $this->renderXml($resDto, $context),
            ResponseFormat::Text   => $this->renderText($resDto, $context),
            ResponseFormat::Raw    => $resDto,
        };
    }

    private function renderJson(object $resDto, array $context): object
    {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (method_exists($resDto, 'setContent')) {
            $resDto->setContent($json ?: '');
        }
        if (method_exists($resDto, 'setHeader')) {
            $resDto->setHeader('Content-Type', 'application/json');
        }
        return $resDto;
    }

    private function renderLayout(object $resDto, ?object $reqDto, string $handle, array $context, ?string $rendererClass): object
    {
        // Resources that render via Twig template inheritance (HtmlResponse subclasses with a
        // declared template or already-rendered content) bypass LayoutRenderer entirely.
        // LayoutRenderer is intended for slot-based layout composition without Twig inheritance.
        $existingContent = method_exists($resDto, 'getContent') ? $resDto->getContent() : '';

        if ($existingContent === '' && method_exists($resDto, 'getDeclaredTemplate')) {
            $declaredTemplate = $resDto->getDeclaredTemplate();
            if ($declaredTemplate !== null && $declaredTemplate !== '' && method_exists($resDto, 'renderTemplate')) {
                $resDto->renderTemplate($declaredTemplate);
                if (method_exists($resDto, 'getContent')) {
                    $existingContent = $resDto->getContent();
                }
            }
        }

        if ($existingContent !== '') {
            if (method_exists($resDto, 'setHeader')) {
                $resDto->setHeader('Content-Type', 'text/html; charset=utf-8');
            }
            return $resDto;
        }

        $renderer = $rendererClass ?: 'Semitexa\\Ssr\\Layout\\LayoutRenderer';
        if (!class_exists($renderer)) {
            throw new \RuntimeException(
                'LayoutRenderer not found. For HTML pages install semitexa/ssr: composer require semitexa/ssr. Do not implement a custom Twig renderer in the project.'
            );
        }

        if (!isset($context['response'])) {
            $context = ['response' => $context] + $context;
        }
        if (!isset($context['request']) && isset($reqDto)) {
            $context['request'] = $reqDto;
        }
        if (method_exists($resDto, 'getLayoutFrame') && $resDto->getLayoutFrame() !== null) {
            $context['layout_frame'] = $resDto->getLayoutFrame();
        }
        $html = $renderer::renderHandle($handle, $context);
        if (method_exists($resDto, 'setContent')) {
            $resDto->setContent($html);
        }
        if (method_exists($resDto, 'setHeader')) {
            $resDto->setHeader('Content-Type', 'text/html; charset=utf-8');
        }
        return $resDto;
    }

    private function renderXml(object $resDto, array $context): object
    {
        $xml = self::arrayToXml($context, 'response');
        if (method_exists($resDto, 'setContent')) {
            $resDto->setContent($xml);
        }
        if (method_exists($resDto, 'setHeader')) {
            $resDto->setHeader('Content-Type', 'application/xml; charset=utf-8');
        }
        return $resDto;
    }

    private function renderText(object $resDto, array $context): object
    {
        $text = $context['text'] ?? json_encode($context, JSON_PRETTY_PRINT);
        if (method_exists($resDto, 'setContent')) {
            $resDto->setContent($text);
        }
        if (method_exists($resDto, 'setHeader')) {
            $resDto->setHeader('Content-Type', 'text/plain; charset=utf-8');
        }
        return $resDto;
    }

    private static function arrayToXml(array $data, string $rootElement = 'root'): string
    {
        $xml = new \SimpleXMLElement("<{$rootElement}/>");
        self::arrayToXmlRecursive($data, $xml);
        $dom = dom_import_simplexml($xml)->ownerDocument;
        $dom->formatOutput = true;
        return $dom->saveXML();
    }

    private static function arrayToXmlRecursive(array $data, \SimpleXMLElement $xml): void
    {
        foreach ($data as $key => $value) {
            $key = is_int($key) ? 'item' : $key;
            if (is_array($value)) {
                $child = $xml->addChild($key);
                self::arrayToXmlRecursive($value, $child);
            } else {
                $xml->addChild($key, htmlspecialchars((string) ($value ?? ''), ENT_XML1));
            }
        }
    }

    private static function formatEnumToKey(ResponseFormat $format): string
    {
        return match ($format) {
            ResponseFormat::Json   => 'json',
            ResponseFormat::Layout => 'html',
            ResponseFormat::Xml    => 'xml',
            ResponseFormat::Text   => 'txt',
            ResponseFormat::Raw    => 'json',
        };
    }

    private static function keyToFormatEnum(string $key): ResponseFormat
    {
        return match ($key) {
            'json' => ResponseFormat::Json,
            'html' => ResponseFormat::Layout,
            'xml'  => ResponseFormat::Xml,
            'txt'  => ResponseFormat::Text,
            default => ResponseFormat::Json,
        };
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
