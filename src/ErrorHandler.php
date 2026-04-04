<?php

declare(strict_types=1);

namespace Semitexa\Core;

use Semitexa\Core\Util\ProjectRoot;

/**
 * Centralized error handling configuration
 */
class ErrorHandler
{
    public static function configure(Environment $environment): void
    {
        error_reporting(E_ALL);

        if ($environment->isDev()) {
            // Development mode - show all errors
            ini_set('display_errors', '1');
            ini_set('log_errors', '1');
        } else {
            // Production mode - hide errors
            ini_set('display_errors', '0');
            ini_set('log_errors', '1');
            ini_set('error_log', ProjectRoot::get() . '/var/log/error.log');
        }
    }
}
