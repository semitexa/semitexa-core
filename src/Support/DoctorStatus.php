<?php

declare(strict_types=1);

namespace Semitexa\Core\Support;

enum DoctorStatus: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';
    case Skip = 'skip';
}
