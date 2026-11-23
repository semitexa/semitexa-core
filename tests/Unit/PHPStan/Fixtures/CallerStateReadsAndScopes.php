<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Core\Attribute\AsService;

/**
 * Fixture: what counts as READING a parameter of the method, and which code
 * inside a method runs as the service.
 *
 * Lines are asserted by the test: append to this file, do not insert.
 */
#[AsService]
final class CallerStateReadsAndScopes
{
    protected string $fixed = '';
    protected string $trimmed = '';
    /** @var array<int, string> */
    protected array $pair = [];
    protected string $joined = '';
    protected string $first = '';
    protected string $second = '';
    protected ?\Closure $shadowing = null;
    protected ?\Closure $capturing = null;
    protected ?\Closure $arrowShadowing = null;
    protected ?\Closure $arrowCapturing = null;
    protected ?\Closure $staticCapturing = null;
    protected string $viaClosure = '';
    protected string $viaClosureParameter = '';
    protected string $viaUnusedClosure = '';
    protected string $viaArrow = '';

    /** NOT reported: the parameter is assigned inside the expression, never read. */
    public function fixed(string $tenant): void
    {
        $this->fixed = ($tenant = 'default');
    }

    /** REPORTED: read inside a call. */
    public function trimmed(string $tenant): void
    {
        $this->trimmed = trim($tenant);
    }

    /** NOT reported: both parameters are destructuring targets. */
    public function pair(string $left, string $right): void
    {
        $this->pair = ([$left, $right] = ['a', 'b']);
    }

    /** REPORTED: a compound assignment reads its target. */
    public function joined(string $suffix): void
    {
        $this->joined = ($suffix .= '!');
    }

    /**
     * REPORTED, both: each target of a destructuring.
     *
     * @param array{string, string} $names
     */
    public function names(array $names): void
    {
        [
            $this->first,
            $this->second,
        ] = $names;
    }

    /** NOT reported: the closure's own parameter takes the name. */
    public function shadowing(string $tenant): void
    {
        $this->shadowing = function (string $tenant): string {
            return $tenant;
        };
    }

    /** REPORTED: the closure captures the caller's value. */
    public function capturing(string $tenant): void
    {
        $this->capturing = function () use ($tenant): string {
            return $tenant;
        };
    }

    /** NOT reported: the arrow function's own parameter takes the name. */
    public function arrowShadowing(string $tenant): void
    {
        $this->arrowShadowing = fn (string $tenant): string => $tenant;
    }

    /** REPORTED: an arrow function sees the method's parameter. */
    public function arrowCapturing(string $tenant): void
    {
        $this->arrowCapturing = fn (): string => $tenant;
    }

    /** REPORTED: a static closure still captures the caller's value. */
    public function staticCapturing(string $tenant): void
    {
        $this->staticCapturing = static function () use ($tenant): string {
            return $tenant;
        };
    }

    /** REPORTED: a closure that is not static writes to the service. */
    public function viaClosure(string $tenant): void
    {
        $write = function () use ($tenant): void {
            $this->viaClosure = $tenant;
        };
        $write();
    }

    /** NOT reported (a named blind spot): the value is the closure's parameter. */
    public function viaClosureParameter(string $tenant): void
    {
        $write = function (string $tenant): void {
            $this->viaClosureParameter = $tenant;
        };
        $write($tenant);
    }

    /** NOT reported: without `use` the closure's $tenant is not the method's. */
    public function viaUnusedClosure(string $tenant): void
    {
        $write = function (): void {
            $tenant = 'own';
            $this->viaUnusedClosure = $tenant;
        };
        $write();
    }

    /** REPORTED: an arrow function that is not static writes to the service. */
    public function viaArrow(string $tenant): void
    {
        $write = fn (): string => $this->viaArrow = $tenant;
        $write();
    }
}
