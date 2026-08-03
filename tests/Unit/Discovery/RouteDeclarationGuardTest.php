<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\SseGateModel;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Discovery\RouteDeclarationGuard;
use Semitexa\Core\Exception\ConfigurationException;
use Semitexa\Core\Exception\ConflictException;

/**
 * Direct tests for {@see RouteDeclarationGuard}, split out of
 * AttributeDiscovery::registerResolvedRoutes by ep-slay-attribute-discovery
 * (tk-ad-route-collector).
 *
 * The SSE half was previously reachable only by reflecting into a private static
 * from RouteTransportMetadataTest. The reserved-path half had no test at all —
 * it was fifteen lines inlined in the middle of a 115-line method, and the only
 * way to exercise it was to boot the whole framework with a deliberately
 * malicious payload class.
 */
final class RouteDeclarationGuardTest extends TestCase
{
    // ---- reserved paths ---------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function reservedPaths(): array
    {
        return [
            'kiss' => ['/__semitexa_kiss'],
            'hug' => ['/__semitexa_hug'],
            'trailing slash is still the same path' => ['/__semitexa_kiss/'],
        ];
    }

    #[Test]
    #[DataProvider('reservedPaths')]
    public function an_application_class_may_not_claim_a_framework_path(string $path): void
    {
        $this->expectException(ConflictException::class);
        $this->expectExceptionMessageMatches('/reserved by the Semitexa framework/');

        (new RouteDeclarationGuard())->assertPathNotReserved($path, 'App\\Payload\\SneakyPayload');
    }

    #[Test]
    public function the_framework_may_claim_its_own_reserved_paths(): void
    {
        $guard = new RouteDeclarationGuard();

        $guard->assertPathNotReserved('/__semitexa_kiss', 'Semitexa\\Ssr\\Application\\Payload\\KissPayload');
        $guard->assertPathNotReserved('/__semitexa_hug', 'Semitexa\\Core\\Application\\Payload\\HugPayload');

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function an_ordinary_path_is_never_reserved(): void
    {
        (new RouteDeclarationGuard())->assertPathNotReserved('/blog/posts', 'App\\Payload\\PostsPayload');

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function a_path_that_merely_starts_with_a_reserved_one_is_allowed(): void
    {
        // The check is on the whole path, not a prefix — /__semitexa_kissing is
        // somebody else's route and must not be seized by the framework.
        (new RouteDeclarationGuard())->assertPathNotReserved('/__semitexa_kissing', 'App\\Payload\\P');

        $this->expectNotToPerformAssertions();
    }

    // ---- SSE gate model ---------------------------------------------------

    #[Test]
    public function an_sse_route_without_a_declared_gate_model_fails_the_boot(): void
    {
        // The coverage hole this guard exists to close: a stream that never says
        // how it is gated.
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/no sseGateModel/');

        (new RouteDeclarationGuard())->assertSseGateCoherence(
            TransportType::Sse->value,
            null,
            PayloadAccessType::Public,
            'App\\Payload\\UngatedStreamPayload',
        );
    }

    #[Test]
    public function a_subject_gate_on_a_public_endpoint_fails_the_boot(): void
    {
        // A Subject gate re-authorizes the session subject; a public endpoint
        // has no subject, so the declaration is incoherent rather than merely
        // unusual.
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/but is #\[AsPublicPayload\]/');

        (new RouteDeclarationGuard())->assertSseGateCoherence(
            TransportType::Sse->value,
            SseGateModel::Subject,
            PayloadAccessType::Public,
            'App\\Payload\\PublicSubjectStreamPayload',
        );
    }

    /**
     * @return array<string, array{SseGateModel}>
     */
    public static function inHandlerGateModels(): array
    {
        return [
            'channel token' => [SseGateModel::ChannelToken],
            'bearer session' => [SseGateModel::BearerSession],
        ];
    }

    #[Test]
    #[DataProvider('inHandlerGateModels')]
    public function a_public_stream_declaring_an_in_handler_gate_passes(SseGateModel $model): void
    {
        // The guard must not be theater in the other direction either: a public
        // stream gated inside the handler is legitimate, and saying so is
        // exactly what the guard asks for.
        (new RouteDeclarationGuard())->assertSseGateCoherence(
            TransportType::Sse->value,
            $model,
            PayloadAccessType::Public,
            'App\\Payload\\TokenGatedStreamPayload',
        );

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function a_subject_gate_on_a_protected_endpoint_passes(): void
    {
        (new RouteDeclarationGuard())->assertSseGateCoherence(
            TransportType::Sse->value,
            SseGateModel::Subject,
            PayloadAccessType::Protected,
            'App\\Payload\\ProtectedStreamPayload',
        );

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function a_plain_http_route_needs_no_gate_model(): void
    {
        // The guard keys on the SSE transport. Requiring a gate model of every
        // route would make the attribute noise on hundreds of ordinary pages.
        (new RouteDeclarationGuard())->assertSseGateCoherence(
            TransportType::Http->value,
            null,
            PayloadAccessType::Public,
            'App\\Payload\\AboutPagePayload',
        );

        $this->expectNotToPerformAssertions();
    }
}
