<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

/**
 * Defines the transport mechanism for a payload endpoint.
 *
 * Used in #[AsPayload(transport: TransportType::Sse)] to explicitly classify
 * how the endpoint communicates with clients. Defaults to HTTP.
 *
 * Downstream systems (e.g. sitemap generation, API schema) use this to make
 * explicit decisions instead of guessing from path names or content-types.
 */
enum TransportType: string
{
    /** Standard HTTP request/response. Default for all endpoints. */
    case Http = 'http';

    /** Server-Sent Events: long-lived GET connection, server pushes events. */
    case Sse = 'sse';

    /** Raw streaming response (chunked transfer, binary, etc.). */
    case Stream = 'stream';
}
