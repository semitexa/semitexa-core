<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

use Semitexa\Core\Attribute\SseGateModel;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Exception\ConfigurationException;
use Semitexa\Core\Exception\ConflictException;

/**
 * Boot-time guards that reject an illegal route declaration outright.
 *
 * Both checks here are deliberately fatal. Discovery swallows most per-class
 * failures into {@see BootDiagnostics} so one bad class cannot take down a
 * worker, but these two describe a route that is *wrong* rather than merely
 * broken — a path claimed from under the framework, or a stream that cannot
 * prove it is gated. Downgrading either to a skip would turn a loud boot failure
 * into a quietly missing or quietly ungated endpoint, which is the worse outcome
 * in both cases.
 *
 * They run against the override-resolved, actually-routed candidate: checking
 * every candidate would fire on declarations that lose their route and never
 * serve a request.
 */
final class RouteDeclarationGuard
{
    /**
     * Paths the framework serves itself and no application may claim.
     */
    private const RESERVED_PATHS = ['/__semitexa_kiss', '/__semitexa_hug'];

    /**
     * Namespaces permitted to declare a reserved path — the framework's own.
     */
    private const FRAMEWORK_NAMESPACES = ['Semitexa\\Ssr\\', 'Semitexa\\Core\\'];

    /**
     * @throws ConflictException when an application class claims a framework path
     */
    public function assertPathNotReserved(string $path, string $className): void
    {
        if (!in_array(rtrim($path, '/'), self::RESERVED_PATHS, true)) {
            return;
        }

        foreach (self::FRAMEWORK_NAMESPACES as $namespace) {
            if (str_starts_with($className, $namespace)) {
                return;
            }
        }

        throw new ConflictException(
            "Route path '{$path}' is reserved by the Semitexa framework and cannot be claimed by non-framework class {$className}."
        );
    }

    /**
     * SSE gate guard (discovery-time, not runtime).
     *
     * The rule: an SSE endpoint must declare a provable authorization gate model.
     * The discriminator is the *presence* of a declared fact, not inference of
     * behavior — so the guard is neither theater (an ungated public stream
     * declares nothing → fails) nor false-fail (a legitimate token/bearer-gated
     * public stream declares its in-handler model → passes).
     *
     * It keys on `transport: TransportType::Sse` rather than `produces`, because
     * a broader `produces` check would still miss `/__semitexa_kiss` — which
     * therefore must also declare the flag.
     *
     *  - SSE + no gate model            → boot-fail (the coverage hole).
     *  - SseGateModel::Subject + Public → boot-fail (a Subject gate re-authorizes
     *                                     the session subject; a public endpoint
     *                                     has none).
     *  - ChannelToken / BearerSession on a public route → pass (gate is in-handler).
     *
     * @throws ConfigurationException
     */
    public function assertSseGateCoherence(
        string $transportValue,
        ?SseGateModel $sseGateModel,
        PayloadAccessType $accessType,
        string $className,
    ): void {
        $isSse = $transportValue === TransportType::Sse->value;

        if ($isSse && $sseGateModel === null) {
            throw new ConfigurationException(sprintf(
                'Payload %s declares transport: TransportType::Sse but no sseGateModel. '
                . 'Every SSE endpoint must declare a provable authorization gate model '
                . '(sseGateModel: SseGateModel::Subject, ::ChannelToken, or ::BearerSession) '
                . 'so the boot guard can prove the stream is gated rather than silently ungated. '
                . 'Use ::Subject for a subject-re-authorized stream, or ::ChannelToken / '
                . '::BearerSession for an in-handler-gated public stream.',
                $className,
            ));
        }

        if ($sseGateModel === SseGateModel::Subject && $accessType === PayloadAccessType::Public) {
            throw new ConfigurationException(sprintf(
                'Payload %s declares sseGateModel: SseGateModel::Subject but is #[AsPublicPayload]. '
                . 'A Subject gate re-authorizes the session subject on every tick and a public '
                . 'endpoint has no subject to re-authorize. Use #[AsProtectedPayload] or '
                . '#[AsServicePayload], or declare an in-handler gate model '
                . '(SseGateModel::ChannelToken / ::BearerSession).',
                $className,
            ));
        }
    }
}
