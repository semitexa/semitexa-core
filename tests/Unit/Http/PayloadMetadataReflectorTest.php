<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Attribute\LiveFilterParam;
use Semitexa\Core\Attribute\SseGateModel;
use Semitexa\Core\Attribute\TransportType;
use Semitexa\Core\Http\PayloadMetadataReflector;

/**
 * Phase 1 (OPTIONS capability advertisement): the `#[LiveFilterParam]` marker
 * gates the advertised `sse-update` mode, and an SSE route's `sseGateModel` is
 * surfaced. Advertisement only — no serving behavior is exercised here.
 */
final class PayloadMetadataReflectorTest extends TestCase
{
    protected function setUp(): void
    {
        PayloadMetadataReflector::clearCache();
    }

    #[Test]
    public function sse_payload_with_live_filter_param_advertises_sse_update(): void
    {
        $doc = PayloadMetadataReflector::describe(SseWithLiveFilterFixture::class);

        // Additive: plain + sse stay; sse-update is added because a field carries the marker.
        self::assertSame(['plain', 'sse', 'sse-update'], $doc['modes']);
        self::assertContains('plain', $doc['modes']);
        self::assertContains('sse', $doc['modes']);
    }

    #[Test]
    public function sse_payload_without_live_filter_param_omits_sse_update(): void
    {
        $doc = PayloadMetadataReflector::describe(SseNoLiveFilterFixture::class);

        // The marker is the gate: absent → sse-update is NOT advertised.
        self::assertSame(['plain', 'sse'], $doc['modes']);
        self::assertNotContains('sse-update', $doc['modes']);
    }

    #[Test]
    public function sse_route_surfaces_gate_model_by_case_name(): void
    {
        $doc = PayloadMetadataReflector::describe(SseWithLiveFilterFixture::class);

        self::assertArrayHasKey('sseGateModel', $doc);
        self::assertSame('BearerSession', $doc['sseGateModel']);
    }

    #[Test]
    public function marker_drives_per_field_filter_flag(): void
    {
        $doc = PayloadMetadataReflector::describe(SseWithLiveFilterFixture::class);

        $byName = [];
        foreach ($doc['fields'] as $field) {
            $byName[$field['name']] = $field;
        }

        self::assertTrue($byName['q']['filter'], 'a marked field reports filter:true');
        self::assertFalse($byName['sessionId']['filter'], 'an unmarked transport field reports filter:false');
    }

    #[Test]
    public function non_sse_payload_advertises_only_plain_and_no_gate_model(): void
    {
        $doc = PayloadMetadataReflector::describe(PlainHttpFixture::class);

        self::assertSame(['plain'], $doc['modes']);
        self::assertArrayNotHasKey('sseGateModel', $doc);
    }
}

#[AsPublicPayload(
    path: '/test/sse-with-filter',
    methods: ['GET'],
    transport: TransportType::Sse,
    sseGateModel: SseGateModel::BearerSession,
)]
class SseWithLiveFilterFixture
{
    #[LiveFilterParam]
    protected ?string $q = null;
    protected ?string $sessionId = null;

    public function setQ(?string $q): void
    {
        $this->q = $q;
    }

    public function setSessionId(?string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }
}

#[AsPublicPayload(
    path: '/test/sse-no-filter',
    methods: ['GET'],
    transport: TransportType::Sse,
    sseGateModel: SseGateModel::BearerSession,
)]
class SseNoLiveFilterFixture
{
    protected ?string $q = null;

    public function setQ(?string $q): void
    {
        $this->q = $q;
    }
}

#[AsPublicPayload(
    path: '/test/plain',
    methods: ['POST'],
)]
class PlainHttpFixture
{
    protected ?string $q = null;

    public function setQ(?string $q): void
    {
        $this->q = $q;
    }
}
