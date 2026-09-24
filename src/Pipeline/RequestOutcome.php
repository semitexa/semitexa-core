<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

use Semitexa\Core\Exception\NotFoundException;
use Semitexa\Core\HttpResponse;

/**
 * What a request ended as, in the shape the root span's end carries.
 *
 * The observatory counted errors from trace marks alone, and those exist only
 * on the few requests that open a trace buffer. Every other request reached the
 * journal as a bare duration, so a 500 and a 200 looked the same and the panel
 * read "errors 0.0%" while the server was failing.
 */
final class RequestOutcome
{
    /**
     * @return array{http_status?: int, exception?: class-string<\Throwable>}
     */
    public static function traceContext(?HttpResponse $sent, ?\Throwable $escaped): array
    {
        if ($sent !== null) {
            return ['http_status' => $sent->statusCode];
        }
        if ($escaped instanceof NotFoundException) {
            // Application answers it with the error.404 route, as a 404.
            return ['http_status' => 404];
        }

        return $escaped !== null ? ['exception' => $escaped::class] : [];
    }
}
