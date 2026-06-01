<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline\ReRun;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\LiveFilterParam;
use Semitexa\Core\Pipeline\ReRun\LiveFilterParamOverride;

/**
 * Intended Grid Model · Phase 2 (C3) — the structural anti-poisoning boundary.
 *
 * Proves the invariant that matters more than shipping: a view-change command may
 * override a cached-DTO field IF AND ONLY IF that field carries
 * {@see LiveFilterParam}. A param targeting a non-marked (identity / session /
 * tenant) field is IGNORED by construction — the override has no code path that
 * writes a non-marked property. This is the same guarantee class as R2
 * anti-poisoning: impossible to violate, not merely documented.
 */
final class LiveFilterParamOverrideTest extends TestCase
{
    // -----------------------------------------------------------------------
    // The DECISIVE negative: a marked filter IS applied, an unmarked identity
    // field is IGNORED — in the SAME command.
    // -----------------------------------------------------------------------

    #[Test]
    public function a_marked_field_is_applied_and_an_unmarked_identity_field_is_ignored(): void
    {
        $dto = new LiveFilterFixtureDto();
        $dto->setSessionId('sse_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'); // the frozen identity

        $result = LiveFilterParamOverride::apply($dto, [
            'page' => '3',                                          // marked → applied
            'sessionId' => 'sse_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',  // UNMARKED → must be ignored
        ]);

        // The marked filter took effect...
        self::assertSame(3, $dto->getPage(), 'the marked filter param overrode the cached value');
        // ...the identity field did NOT — by construction, not by chance.
        self::assertSame(
            'sse_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            $dto->getSessionId(),
            'the unmarked identity field is structurally un-overridable',
        );

        self::assertSame(['page'], $result['applied']);
        self::assertSame(['sessionId'], $result['ignored'], 'the identity param is reported ignored — the boundary evidence');
    }

    #[Test]
    public function every_marked_filter_param_overrides_and_coerces_to_the_declared_type(): void
    {
        $dto = new LiveFilterFixtureDto();

        $result = LiveFilterParamOverride::apply($dto, [
            'q' => '  acme  ',     // string, trimmed
            'action' => 'lead',
            'limit' => '50',       // int-coerced
            'sort' => '-submittedAt',
            'cursor' => 'c123',
            'page' => '7',         // int-coerced
        ]);

        self::assertSame('acme', $dto->getQ());
        self::assertSame('lead', $dto->getAction());
        self::assertSame(50, $dto->getLimit());
        self::assertSame('-submittedAt', $dto->getSort());
        self::assertSame('c123', $dto->getCursor());
        self::assertSame(7, $dto->getPage());

        self::assertSame(
            ['q', 'action', 'limit', 'sort', 'cursor', 'page'],
            $result['applied'],
        );
        self::assertSame([], $result['ignored']);
    }

    #[Test]
    public function an_unknown_field_is_ignored_not_created(): void
    {
        $dto = new LiveFilterFixtureDto();

        $result = LiveFilterParamOverride::apply($dto, [
            'page' => 2,
            'evil' => 'nope',            // no such property
            'httpRequest' => 'spoofed',  // a real but unmarked transport field
        ]);

        self::assertSame(2, $dto->getPage());
        self::assertSame(['page'], $result['applied']);
        self::assertSame(['evil', 'httpRequest'], $result['ignored']);
    }

    #[Test]
    public function an_empty_override_is_a_noop(): void
    {
        $dto = new LiveFilterFixtureDto();
        $dto->setPage(5);

        $result = LiveFilterParamOverride::apply($dto, []);

        self::assertSame(5, $dto->getPage(), 'no override leaves the cached DTO verbatim');
        self::assertSame(['applied' => [], 'ignored' => []], $result);
    }

    #[Test]
    public function clearing_a_nullable_string_filter_resets_it_to_null(): void
    {
        $dto = new LiveFilterFixtureDto();
        $dto->setQ('acme');

        LiveFilterParamOverride::apply($dto, ['q' => '']);

        self::assertNull($dto->getQ(), 'an empty value on a nullable string filter clears it');
    }
}

/**
 * A fixture mirroring the grid stream DTO's shape: marked filter fields + UNMARKED
 * identity/transport fields. Lives in-test so semitexa-core's unit suite never
 * depends on the app module — the boundary it proves is the attribute's, not any
 * one DTO's.
 */
final class LiveFilterFixtureDto
{
    #[LiveFilterParam]
    protected ?string $q = null;
    #[LiveFilterParam]
    protected ?string $action = null;
    #[LiveFilterParam]
    protected ?int $limit = null;
    #[LiveFilterParam]
    protected ?string $sort = null;
    #[LiveFilterParam]
    protected ?string $cursor = null;
    #[LiveFilterParam]
    protected ?int $page = null;

    // UNMARKED — identity / transport. The override must never touch these.
    protected ?string $sessionId = null;
    protected ?string $httpRequest = null;

    public function setSessionId(?string $v): void
    {
        $this->sessionId = $v;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function setQ(?string $v): void
    {
        $this->q = $v;
    }

    public function getQ(): ?string
    {
        return $this->q;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function getSort(): ?string
    {
        return $this->sort;
    }

    public function getCursor(): ?string
    {
        return $this->cursor;
    }

    public function setPage(?int $v): void
    {
        $this->page = $v;
    }

    public function getPage(): ?int
    {
        return $this->page;
    }
}
