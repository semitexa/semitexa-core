<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\Config;
use Semitexa\Core\Container\InjectionAnalyzer;
use Semitexa\Core\Discovery\BootDiagnostics;

/**
 * What #[Config] actually puts in a property. Until this test nothing did more
 * than read the attribute (tk-parity-proof-gaps): lookup, casting and the
 * default were unproven. The env keys here are unique to this test and are
 * removed again, so the process env is left as it was found.
 */
final class ConfigInjectionTest extends TestCase
{
    private const KEYS = ['SEMITEXA_CFG_TEST_INT', 'SEMITEXA_CFG_TEST_BOOL', 'SEMITEXA_CFG_TEST_MODE', 'SEMITEXA_CFG_TEST_RATIO', 'SEMITEXA_CFG_TEST_NAME'];

    /** @var array<string, string|false> what each key held before the test */
    private array $savedEnv = [];

    private ?BootDiagnostics $savedDiagnostics = null;

    protected function setUp(): void
    {
        foreach (self::KEYS as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }
        // A misspelled value is recorded on the process-wide collector; give the
        // test its own and put the suite's back after.
        $current = new \ReflectionProperty(BootDiagnostics::class, 'current');
        $this->savedDiagnostics = $current->getValue();
        $current->setValue(null, null);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
        (new \ReflectionProperty(BootDiagnostics::class, 'current'))->setValue(null, $this->savedDiagnostics);
    }

    #[Test]
    public function a_set_variable_is_cast_to_the_property_type(): void
    {
        putenv('SEMITEXA_CFG_TEST_INT=42');
        putenv('SEMITEXA_CFG_TEST_BOOL=yes');
        putenv('SEMITEXA_CFG_TEST_MODE=strict');
        putenv('SEMITEXA_CFG_TEST_RATIO=0.25');
        putenv('SEMITEXA_CFG_TEST_NAME=node-a');

        $target = $this->inject();

        self::assertSame(42, $target->int());
        self::assertTrue($target->bool());
        self::assertSame(ConfigInjectionMode::Strict, $target->mode());
        self::assertSame(0.25, $target->ratio());
        self::assertSame('node-a', $target->name());
        self::assertSame(7, $target->constant(), 'no env: the default, always');
    }

    #[Test]
    public function an_unset_variable_falls_back_to_the_default(): void
    {
        $target = $this->inject();

        self::assertSame(30, $target->int());
        self::assertFalse($target->bool());
        self::assertSame(ConfigInjectionMode::Lenient, $target->mode());
        self::assertSame(0.5, $target->ratio());
        self::assertSame('local', $target->name());
    }

    #[Test]
    public function a_value_that_is_not_one_of_the_enum_falls_back_to_the_default(): void
    {
        putenv('SEMITEXA_CFG_TEST_MODE=stirct');

        self::assertSame(ConfigInjectionMode::Lenient, $this->inject()->mode());
    }

    /**
     * A typo in a number used to become 0 — `(int) "3o"` — so a timeout or a
     * limit read from a misspelled .env silently meant "none". The framework's
     * own knobs refuse that (SseEnv: a typo must not take the endpoint down);
     * #[Config] now does too, and keeps the default.
     */
    #[Test]
    public function a_value_that_is_not_a_number_falls_back_to_the_default_instead_of_zero(): void
    {
        putenv('SEMITEXA_CFG_TEST_INT=3o');
        putenv('SEMITEXA_CFG_TEST_RATIO=half');

        $target = $this->inject();

        self::assertSame(30, $target->int());
        self::assertSame(0.5, $target->ratio());
    }

    #[Test]
    public function a_zero_padded_whole_number_is_still_that_number(): void
    {
        putenv('SEMITEXA_CFG_TEST_INT=08');

        self::assertSame(8, $this->inject()->int());
    }

    private function inject(): ConfigInjectionTarget
    {
        $analyzer = (new \ReflectionClass(InjectionAnalyzer::class))->newInstanceWithoutConstructor();
        $target = new ConfigInjectionTarget();
        $analyzer->injectConfigProperties($target, ConfigInjectionTarget::class, new \ReflectionClass(ConfigInjectionTarget::class));

        return $target;
    }
}

enum ConfigInjectionMode: string
{
    case Strict = 'strict';
    case Lenient = 'lenient';
}

final class ConfigInjectionTarget
{
    #[Config(env: 'SEMITEXA_CFG_TEST_INT', default: 30)]
    protected int $int;

    #[Config(env: 'SEMITEXA_CFG_TEST_BOOL', default: false)]
    protected bool $bool;

    #[Config(env: 'SEMITEXA_CFG_TEST_MODE', default: ConfigInjectionMode::Lenient)]
    protected ConfigInjectionMode $mode;

    #[Config(env: 'SEMITEXA_CFG_TEST_RATIO', default: 0.5)]
    protected float $ratio;

    #[Config(env: 'SEMITEXA_CFG_TEST_NAME', default: 'local')]
    protected string $name;

    #[Config(default: 7)]
    protected int $constant;

    public function int(): int { return $this->int; }
    public function bool(): bool { return $this->bool; }
    public function mode(): ConfigInjectionMode { return $this->mode; }
    public function ratio(): float { return $this->ratio; }
    public function name(): string { return $this->name; }
    public function constant(): int { return $this->constant; }
}
