<?php

declare(strict_types=1);

namespace Semitexa\Core\Http\Client;

/**
 * When a failed call is sent again. None by default: a retry is a decision
 * about the remote side, and resending a POST can charge a card twice.
 *
 * - idempotent(): GET, HEAD, PUT, DELETE, OPTIONS only, exponential backoff
 *   with full jitter (a random wait up to base·2^n, capped), so a fleet of
 *   clients does not retry in lockstep;
 * - linear(): base, 2·base, 3·base… — what the LLM providers always did;
 * - allowingNonIdempotent(): POST and PATCH too, for an API that is known to
 *   tolerate a repeat.
 *
 * Retried: a transport failure worth retrying (HttpTransportKind::isRetryable)
 * and statuses 429, 500, 502, 503, 504. A Retry-After header, in seconds or as
 * a date, is honoured up to the cap.
 */
final readonly class RetryPolicy
{
    private const IDEMPOTENT = ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS'];

    /** No Retry-After, however large, makes a call wait longer than this. */
    public const MAX_RETRY_AFTER_MS = 60_000;
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    public function __construct(
        public int $maxAttempts = 1,
        public int $baseMs = 200,
        public int $capMs = 5000,
        public bool $linear = false,
        public bool $nonIdempotent = false,
    ) {}

    public static function none(): self
    {
        return new self();
    }

    public static function idempotent(int $maxAttempts = 3, int $baseMs = 200, int $capMs = 5000): self
    {
        return new self(max(1, $maxAttempts), max(0, $baseMs), max(0, $capMs));
    }

    public static function linear(int $maxAttempts, int $stepMs = 500, int $capMs = 30_000): self
    {
        return new self(max(1, $maxAttempts), max(0, $stepMs), max(0, $capMs), true);
    }

    public function allowingNonIdempotent(): self
    {
        return new self($this->maxAttempts, $this->baseMs, $this->capMs, $this->linear, true);
    }

    public function appliesTo(string $method): bool
    {
        return $this->maxAttempts > 1 && ($this->nonIdempotent || in_array(strtoupper($method), self::IDEMPOTENT, true));
    }

    public function retriesStatus(int $status): bool
    {
        return in_array($status, self::RETRYABLE_STATUSES, true);
    }

    /**
     * Milliseconds to wait after attempt $attempt (1-based) failed.
     *
     * @param \Closure(int, int): int $random inclusive bounds, like random_int
     */
    public function delayMs(int $attempt, ?int $retryAfterMs, \Closure $random): int
    {
        if ($retryAfterMs !== null) {
            return min(max(0, $retryAfterMs), $this->capMs, self::MAX_RETRY_AFTER_MS);
        }

        if ($this->linear) {
            return min($this->capMs, $this->baseMs * $attempt);
        }

        $ceiling = (int) min($this->capMs, $this->baseMs * (2 ** min(30, $attempt - 1)));

        return $ceiling > 0 ? $random(0, $ceiling) : 0;
    }

    /**
     * A Retry-After value — seconds, or an HTTP date — in milliseconds from
     * $now, clamped to MAX_RETRY_AFTER_MS: the server chooses the number, and
     * `99999999999999999999` must not become a float, nor a week of sleep.
     */
    public static function retryAfterMs(?string $value, int $now): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (ctype_digit($value)) {
            return strlen($value) > 6 ? self::MAX_RETRY_AFTER_MS : min(self::MAX_RETRY_AFTER_MS, (int) $value * 1000);
        }
        // An HTTP date names a weekday and a GMT time; strtotime() alone would
        // also take "+10 years".
        if (preg_match('/^[A-Za-z]{3}, \d{2} [A-Za-z]{3} \d{4} \d{2}:\d{2}:\d{2} GMT$/', $value) !== 1) {
            return null;
        }
        $at = strtotime($value);
        if ($at === false) {
            return null;
        }

        return (int) min(self::MAX_RETRY_AFTER_MS, max(0, $at - $now) * 1000);
    }
}
