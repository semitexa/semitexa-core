<?php

declare(strict_types=1);

namespace Semitexa\Core\Lifecycle;

/**
 * "This process is re-running something for inspection — nothing may leave it."
 *
 * `ai:trace replay` re-runs a recorded handler inside a transaction it always
 * rolls back, and rebinds every queue transport to a captor so a handoff never
 * reaches a broker. What it could not reach was a SYNC listener that calls an
 * external service directly: mail, a webhook, an LLM. Those ran for real, out
 * of a command whose entire promise is that nothing happens.
 *
 * The obvious fix — have the replay runner reach into each package and swap its
 * transport — is the wrong shape twice over. It puts semitexa/dev in the
 * position of knowing every outbound port in the ecosystem, and it can only
 * ask "is that package installed?" with a runtime class check, which is exactly
 * what `semitexa.explicitOptionalDependency` forbids and for good reason.
 *
 * So the flag lives HERE, in the one package every other one already requires,
 * and each outbound port consults it on the way out. The replay runner turns it
 * on and knows nothing about who honours it; a port honours it and knows
 * nothing about replay. Neither has to name the other.
 *
 * WHAT WAS STOPPED IS REPORTED, not silently dropped. A replay whose listener
 * tried to email a customer should say so — that is a finding, and swallowing
 * it would make the sandbox lie in the other direction.
 *
 * Process-global, like {@see \Semitexa\Core\Queue\QueueTransportRegistry}: a
 * replay owns its process. It is not coroutine-scoped and must not be used to
 * sandbox one request out of many being served concurrently.
 */
final class SandboxGuard
{
    private static bool $active = false;

    private static string $reason = '';

    /** @var list<array{port: string, detail: array<string, mixed>, at: string}> */
    private static array $withheld = [];

    /**
     * Enter the sandbox. `$reason` is shown to whoever asks why an outbound
     * call did not happen, so name the activity, not the mechanism.
     */
    public static function enter(string $reason): void
    {
        self::$active = true;
        self::$reason = $reason;
        self::$withheld = [];
    }

    public static function leave(): void
    {
        self::$active = false;
        self::$reason = '';
    }

    public static function isActive(): bool
    {
        return self::$active;
    }

    public static function reason(): string
    {
        return self::$reason;
    }

    /**
     * A port records what it did not do.
     *
     * Called by the port itself, on the way to returning a no-op — so the
     * caller's flow is unchanged and the fact survives to the report.
     *
     * @param array<string, mixed> $detail whatever identifies the attempt;
     *        keep it small and free of payload bodies
     */
    public static function withhold(string $port, array $detail = []): void
    {
        self::$withheld[] = [
            'port' => $port,
            'detail' => $detail,
            'at' => gmdate('c'),
        ];
    }

    /**
     * Everything withheld since the last {@see enter()}.
     *
     * @return list<array{port: string, detail: array<string, mixed>, at: string}>
     */
    public static function withheldCalls(): array
    {
        return self::$withheld;
    }

    /** For tests and for a worker that must not inherit another run's state. */
    public static function reset(): void
    {
        self::$active = false;
        self::$reason = '';
        self::$withheld = [];
    }
}
