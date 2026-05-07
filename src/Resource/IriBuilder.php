<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\ExecutionScoped;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Request;
use Semitexa\Core\Resource\Exception\MissingHrefTemplateException;
use Semitexa\Core\Resource\Exception\MissingHrefTemplateValueException;
use Semitexa\Core\Resource\Exception\UnknownResourceRelationException;
use Semitexa\Core\Resource\Metadata\ResourceFieldMetadata;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\Metadata\ResourceObjectMetadata;

/**
 * Resolves URI templates declared on `#[ResourceRef]` / `#[ResourceRefList]`
 * against a parent's runtime values. Request-scoped: a fresh clone is created
 * per request so `$request` reflects the current call.
 *
 * Tests construct IriBuilder directly via `forTesting(...)` since the
 * container is not available in unit tests.
 */
#[AsService]
#[ExecutionScoped]
final class IriBuilder
{
    #[InjectAsReadonly]
    protected ResourceMetadataRegistry $registry;

    #[InjectAsMutable]
    protected Request $request;

    private ?string $baseUrlOverride = null;

    /** Bypass property injection for unit tests. */
    public static function forTesting(ResourceMetadataRegistry $registry, string $baseUrl = ''): self
    {
        $i = new self();
        $i->registry        = $registry;
        $i->baseUrlOverride = $baseUrl;
        return $i;
    }

    public function baseUrl(): string
    {
        if ($this->baseUrlOverride !== null) {
            return $this->baseUrlOverride;
        }
        if (!isset($this->request)) {
            return '';
        }
        $scheme = $this->request->getScheme() !== '' ? $this->request->getScheme() : 'http';
        $host   = $this->request->getHost();
        return $host === '' ? '' : sprintf('%s://%s', $scheme, $host);
    }

    /**
     * Resolve the href template declared on `$parentClass::$relation`.
     *
     * @param class-string                        $parentClass
     * @param array<string, scalar|\Stringable>   $parentValues  e.g. ['id' => '123']
     */
    public function forRelation(string $parentClass, array $parentValues, string $relation): string
    {
        $metadata = $this->registry->require($parentClass);
        $field    = $metadata->getField($relation);

        if ($field === null) {
            throw new UnknownResourceRelationException($metadata->type, $relation);
        }
        if ($field->hrefTemplate === null) {
            throw new MissingHrefTemplateException($metadata->type, $relation);
        }

        return $this->prefix($this->resolveTemplate($metadata, $field, $parentValues));
    }

    /**
     * Generic template resolver — substitutes {field} placeholders. Use this
     * when the handler constructs an href without going through metadata
     * (e.g. parent identity for ResourceRef::to()).
     *
     * @param array<string, scalar|\Stringable> $values
     */
    public function resolve(string $template, array $values): string
    {
        return $this->prefix($this->substitute($template, $values));
    }

    /**
     * @param array<string, scalar|\Stringable> $parentValues
     */
    private function resolveTemplate(
        ResourceObjectMetadata $parentMetadata,
        ResourceFieldMetadata $field,
        array $parentValues,
    ): string {
        if ($field->hrefTemplate === null) {
            throw new \LogicException('resolveTemplate called with null hrefTemplate.');
        }

        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $field->hrefTemplate, $matches);
        foreach ($matches[1] as $placeholder) {
            if (!array_key_exists($placeholder, $parentValues)) {
                throw new MissingHrefTemplateValueException(
                    $parentMetadata->type,
                    $field->name,
                    $placeholder,
                );
            }
        }

        return $this->substitute($field->hrefTemplate, $parentValues);
    }

    /**
     * @param array<string, scalar|\Stringable> $values
     */
    private function substitute(string $template, array $values): string
    {
        return preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use ($values): string {
                $key = $m[1];
                if (!array_key_exists($key, $values)) {
                    return $m[0];
                }
                $raw = $values[$key];
                $string = is_scalar($raw) || $raw instanceof \Stringable ? (string) $raw : '';
                return rawurlencode($string);
            },
            $template,
        ) ?? $template;
    }

    private function prefix(string $resolved): string
    {
        $base = $this->baseUrl();
        if ($base === '') {
            return $resolved;
        }
        if (str_starts_with($resolved, 'http://') || str_starts_with($resolved, 'https://')) {
            return $resolved;
        }

        return rtrim($base, '/') . '/' . ltrim($resolved, '/');
    }
}
