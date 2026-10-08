<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Auth\AuthBootstrapperInterface;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Contract\ValidatablePayloadInterface;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Discovery\PayloadPartRegistry;
use Semitexa\Core\Exception\PayloadValidationException;
use Semitexa\Core\Exception\PipelineException;
use Semitexa\Core\Exception\ValidationException;
use Semitexa\Core\Http\PayloadFactory;
use Semitexa\Core\Http\PayloadHydrator;
use Semitexa\Core\Request;

/**
 * Turns a request into a route's validated payload: a bare instance, the
 * pre-hydration auth gate, then hydration and validation.
 *
 * Shared by {@see RouteExecutor::execute()} and {@see RouteExecutor::admit()}
 * so a request admitted for execution elsewhere (a feed subscription on HUG)
 * passes exactly the steps a direct request does.
 */
final class PayloadAdmission
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ?AuthBootstrapperInterface $authBootstrapper = null,
    ) {}

    /**
     * Steps 1a–1c of execute(): a bare payload, the pre-hydration auth gate
     * (protected routes refuse before the body is touched), then hydration and
     * validation unless this is an OPTIONS probe.
     */
    public function gateAndHydrate(DiscoveredRoute $route, Request $request, bool $isOptions, ?RequestTracerInterface $tracer): object
    {
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
            $tracer?->begin('auth.pre_hydration_gate', ['gate' => $gate::class, 'method' => 'gate']);
            $gate->gate($reqDto, $request, $this->authBootstrapper);
            $tracer?->end('auth.pre_hydration_gate');
        } else {
            $tracer?->mark('auth.pre_hydration_gate.absent');
        }

        // 1c. Hydrate and Validate — skipped for OPTIONS. The endpoint is
        //     only being described, so the request body is irrelevant and a
        //     payload's ValidatablePayloadInterface::validate() business
        //     rules must not reject an (empty) OPTIONS probe. OPTIONS reports
        //     type-level shape only; validate() is not reflected.
        if ($isOptions) {
            $tracer?->mark('payload.hydrate_and_validate.skipped', ['reason' => 'OPTIONS probe']);
            return $reqDto;
        }
        $tracer?->begin('payload.hydrate_and_validate', ['payload' => $reqDto::class]);
        [$reqDto, $validationError] = $this->fillAndValidatePayload($reqDto, $request);
        // The hydrated DTO rides along as an object; the tracer decides
        // what of its state survives (redacted, size-bounded snapshot).
        // Passing values here would force the executor to know the
        // redaction rules, which belong to the observer, not the path.
        $tracer?->end('payload.hydrate_and_validate', [
            'rejected' => $validationError !== null,
            'payload_snapshot' => $reqDto,
        ]);
        // After the span closes: an asymmetric begin/end is its own bug class.
        if ($validationError !== null) {
            throw $validationError;
        }

        return $reqDto;
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
     * @return array{0: object, 1: ?PayloadValidationException}
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
            // Re-typed, not rethrown: raised HERE it means a malformed request rather than a domain rule.
            return [$reqDto, new PayloadValidationException($e->getErrors())];
        } catch (\Semitexa\Core\Http\Exception\TypeMismatchException $e) {
            return [$reqDto, new PayloadValidationException([$e->field => [$e->getMessage()]])];
        } catch (\Throwable $e) {
            // Security: suppress exception messages in production (VULN-007)
            // Only expose details in debug mode for development
            $httpRequest = method_exists($reqDto, 'getHttpRequest') ? $reqDto->getHttpRequest() : null;
            $message = $httpRequest instanceof Request && self::isDebugMode($httpRequest)
                ? $e->getMessage()
                : 'Request body could not be processed';
            return [$reqDto, new PayloadValidationException(['_body' => [$message]])];
        }

        return [$reqDto, null];
    }

    /**
     * Check if debug mode is enabled via the application environment configuration.
     */
    private static function isDebugMode(?Request $_request): bool
    {
        $debug = \Semitexa\Core\Environment::create()->appDebug;
        return filter_var($debug, FILTER_VALIDATE_BOOL);
    }

    private function getPayloadPartRegistry(): PayloadPartRegistry
    {
        if ($this->container->has(PayloadPartRegistry::class)) {
            /** @var PayloadPartRegistry $registry */
            $registry = $this->container->get(PayloadPartRegistry::class);
            return $registry;
        }

        /** @var AttributeDiscovery $discovery */
        $discovery = $this->container->get(AttributeDiscovery::class);
        return $discovery->getPayloadPartRegistry();
    }
}
