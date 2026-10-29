<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures\DeprecatedApi;

/**
 * @api
 */
final class ApiClock
{
    /** @deprecated 2026.10.29 Use ApiClock::now() instead. */
    public function current(): int
    {
        return 0;
    }

    /** @deprecated Use ApiClock::now() instead. */
    public function noDate(): int
    {
        return 0;
    }

    /** @deprecated 2026.10.29 */
    public function noReplacement(): int
    {
        return 0;
    }

    /** @deprecated 2999.01.01 Use ApiClock::now() instead. */
    public function future(): int
    {
        return 0;
    }

    /** @deprecated 2026.10.29.1052 Use ApiClock::now() instead. */
    #[\Deprecated('Use ApiClock::now() instead.', since: '2026.10.01')]
    public function attributeDisagrees(): int
    {
        return 0;
    }

    #[\Deprecated('Use ApiClock::now() instead.', since: '2026.10.29')]
    public function attributeOnly(): int
    {
        return 0;
    }

    /**
     * @deprecated 2026.10.29 Use ApiClock::now()
     *     instead, which reads the same clock.
     */
    #[\Deprecated('Use ApiClock::now() instead.', since: '2026.10.29')]
    public function bothAgree(): int
    {
        return 0;
    }

    /** @deprecated whenever */
    private function notApi(): int
    {
        return $this->notApi();
    }

    /** @deprecated */
    public const OLD = 1;
}

final class Internal
{
    /** @deprecated whenever */
    public function anything(): int
    {
        return 0;
    }

    /**
     * @api
     * @deprecated soon
     */
    public function markedOnItsOwn(): int
    {
        return 0;
    }
}

/**
 * @api
 * @deprecated 2026.10.29 Use ApiClock instead.
 */
final class OldClock
{
}
