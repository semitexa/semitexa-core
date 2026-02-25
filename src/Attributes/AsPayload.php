<?php

declare(strict_types=1);

namespace Semitexa\Core\Attributes;

use Attribute;

/**
 * Marks a class as a payload (request) DTO with route information
 *
 * This attribute tells Semitexa that this class should be treated as a request
 * payload and defines the route path, methods, and other options.
 *
 * You can use environment variable references in any attribute value:
 * - `env::VAR_NAME` - reads from .env file, returns empty string if not set
 * - `env::VAR_NAME::default_value` - reads from .env file, returns default if not set (recommended)
 * - `env::VAR_NAME:default_value` - legacy format, also supported for backward compatibility
 *
 * The double colon format (`::`) is recommended because it allows colons in default values.
 *
 * Example:
 * ```php
 * #[AsPayload(
 *     doc: 'docs/attributes/AsPayload.md',
 *     path: 'env::API_LOGIN_PATH::/api/login',
 *     methods: ['POST'],
 *     name: 'env::API_LOGIN_ROUTE_NAME::api.login',
 *     responseWith: 'env::API_LOGIN_RESPONSE_CLASS::LoginApiResponse'
 * )]
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
class AsPayload
{
    public readonly ?string $doc;

    public function __construct(
        ?string $doc = null,
        public ?string $base = null,
        /** Class name of the Request this one overrides (strict chain: only current head can be overridden) */
        public ?string $overrides = null,
        public ?string $responseWith = null,
        public ?string $path = null,
        /** @var list<string>|null $methods */
        public ?array $methods = null,
        public ?string $name = null,
        /** @var array<string, mixed>|null $requirements */
        public ?array $requirements = null,
        /** @var array<string, mixed>|null $defaults */
        public ?array $defaults = null,
        /** @var array<string, mixed>|null $options */
        public ?array $options = null,
        /** @var array<string>|null $tags */
        public ?array $tags = null,
        public ?bool $public = null,
        public string $protocol = 'http',
    ) {
        $this->doc = $doc;
    }
}
