<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

use Semitexa\Core\Http\Request;
use Semitexa\Core\Http\Response;
use Semitexa\Core\Http\RequestDtoHydrator;
use Semitexa\Core\Http\PayloadValidator;
use Semitexa\Core\Http\Response\GenericResponse;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Http\Response\ResponseFormat;
use Semitexa\Core\Pipeline\Exception\AuthenticationRequiredException;
use Semitexa\Core\Pipeline\Exception\AccessDeniedException;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Container\RequestScopedContainer;

class RouteExecutor
{
    public function __construct(
        private readonly RequestScopedContainer $requestScopedContainer,
        private readonly ContainerInterface $container,
        /** @var \Semitexa\Auth\AuthBootstrapper|null */
        private readonly $authBootstrapper = null,
    ) {}

    /**
     * @param array{type?: string, class?: string, handlers?: list<array{class?: string, execution?: string}>, responseClass?: string, method?: string, name?: string} $route
     */
    public function execute(array $route, Request $request): Response
    {
        try {
            // 1. Hydrate and Validate
            [$reqDto, $validationResponse] = $this->hydrateRequest($route, $request);
            if ($validationResponse) {
                return $validationResponse;
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
            );

            // 4. Execute Pipeline
            $pipelineExecutor = new PipelineExecutor($this->requestScopedContainer, $this->container);
            $pipelineExecutor->execute($context);
            $resDto = $context->resourceDto;

            // 5. Render Response
            $resDto = $this->renderResponse($resDto, $reqDto);

            // 6. Adapt to Core Response
            return $this->adaptResponse($resDto);

        } catch (AuthenticationRequiredException $e) {
            return Response::json(['error' => 'Unauthorized', 'message' => $e->getMessage()], 401);
        } catch (AccessDeniedException $e) {
            return Response::json(['error' => 'Forbidden', 'message' => $e->getMessage()], 403);
        }
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

        $reqDto = class_exists($requestClass) ? new $requestClass() : null;
        if (!$reqDto) {
            throw new \RuntimeException("Cannot instantiate request class: {$requestClass}");
        }

        try {
            $reqDto = RequestDtoHydrator::hydrate($reqDto, $request);
            if (method_exists($reqDto, 'setHttpRequest')) {
                $reqDto->setHttpRequest($request);
            }
        } catch (\Semitexa\Core\Http\Exception\TypeMismatchException $e) {
            return [$reqDto, Response::json(['errors' => [$e->field => [$e->getMessage()]]], 422)];
        } catch (\Throwable) {
            // Continue with empty DTO if hydration fails
        }

        $validationResult = PayloadValidator::validate($reqDto, $request);
        if (!$validationResult->isValid()) {
            return [$reqDto, Response::json(['errors' => $validationResult->getErrors()], 422)];
        }

        return [$reqDto, null];
    }

    private function resolveResponseDto(array $route): object
    {
        $responseClass = $route['responseClass'] ?? null;
        $resDto = ($responseClass && class_exists($responseClass)) ? new $responseClass() : null;

        if ($resDto === null) {
            $resDto = new GenericResponse();
        }

        // Apply AsResource defaults from resolved attributes
        $resolvedResponse = AttributeDiscovery::getResolvedResponseAttributes(get_class($resDto));
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
        }

        return $resDto;
    }

    private function renderResponse(object $resDto, ?object $reqDto): object
    {
        if (!method_exists($resDto, 'getRenderHandle')) {
            return $resDto;
        }

        $handle = $resDto->getRenderHandle();
        if (!$handle) {
            return $resDto;
        }

        $context = method_exists($resDto, 'getRenderContext') ? $resDto->getRenderContext() : [];
        /** @var ResponseFormat|null $format */
        $format = method_exists($resDto, 'getRenderFormat') ? $resDto->getRenderFormat() : null;
        if ($format === null) {
            $format = ResponseFormat::Layout;
        }
        $rendererClass = method_exists($resDto, 'getRendererClass') ? $resDto->getRendererClass() : null;

        if ($format === ResponseFormat::Json) {
            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (method_exists($resDto, 'setContent')) {
                $resDto->setContent($json ?: '');
            }
            if (method_exists($resDto, 'setHeader')) {
                $resDto->setHeader('Content-Type', 'application/json');
            }
        } elseif ($format === ResponseFormat::Layout) {
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
