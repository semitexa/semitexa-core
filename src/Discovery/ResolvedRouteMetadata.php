<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

/**
 * Immutable snapshot of all statically resolved information about a single route.
 *
 * Constructed once per request by RouteMetadataResolverInterface and passed into
 * the exception mapping and pipeline extension seams.  External packages attach
 * their own metadata via the open $extensions bag rather than patching discovery
 * internals.
 */
final readonly class ResolvedRouteMetadata
{
    /**
     * @param list<string>       $methods      HTTP methods accepted by this route
     * @param list<string>|null  $produces     Negotiable response formats (null = any)
     * @param list<string>|null  $consumes     Accepted request formats (null = any)
     * @param list<array<string,mixed>> $handlers Discovered handler descriptors
     * @param array<string,string> $requirements Path parameter regex constraints
     * @param array<string,mixed>  $extensions  Open bag for package-specific metadata
     */
    public function __construct(
        public string $path,
        public string $name,
        public array $methods,
        public string $requestClass,
        public string $responseClass,
        public ?array $produces,
        public ?array $consumes,
        public array $handlers,
        public array $requirements,
        public array $extensions,
    ) {}

    /**
     * Return a copy with additional or updated extension keys merged in.
     *
     * @param array<string,mixed> $additions
     */
    public function withExtensions(array $additions): self
    {
        return new self(
            path:         $this->path,
            name:         $this->name,
            methods:      $this->methods,
            requestClass: $this->requestClass,
            responseClass: $this->responseClass,
            produces:     $this->produces,
            consumes:     $this->consumes,
            handlers:     $this->handlers,
            requirements: $this->requirements,
            extensions:   array_merge($this->extensions, $additions),
        );
    }

    /**
     * Whether this route carries a specific extension key (e.g. 'external_api').
     */
    public function hasExtension(string $key): bool
    {
        return array_key_exists($key, $this->extensions);
    }
}
