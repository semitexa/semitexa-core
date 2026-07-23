<?php

declare(strict_types=1);

namespace Semitexa\Core\Contract;

use Semitexa\Core\Support\DoctorResult;

/**
 * A single environment capability probe (PHP extension present, format coder
 * available, transport resolvable, database reachable). Implementations are
 * tagged #[AsDoctorCheck] and MUST have a parameterless constructor —
 * 'system:doctor' instantiates them directly so a broken container can never
 * prevent diagnosing the environment.
 *
 * A check must never throw for an unhealthy environment — that is what
 * DoctorResult::fail() is for. An escaped throwable is reported as a failed
 * check with the exception message.
 */
interface DoctorCheckInterface
{
    public function run(): DoctorResult;
}
