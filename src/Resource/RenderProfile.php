<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

enum RenderProfile: string
{
    case Json    = 'json';
    case JsonLd  = 'json-ld';
    case GraphQL = 'graphql';
    case Html    = 'html';
    case OpenApi = 'openapi';
}
