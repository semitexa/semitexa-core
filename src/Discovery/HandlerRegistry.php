<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

/**
 * Stores handler→payload+resource mappings discovered at boot time.
 * Provides handler lookup by payload and resource class for route execution.
 *
 * Populated during AttributeDiscovery::initialize(), sealed after boot.
 * Readonly for the worker's lifetime — safe to share across coroutines.
 */
final class HandlerRegistry
{
    /** @var array<string, list<array{class: string, payload?: string, resource?: string, execution: string, transport: ?string, queue: ?string, priority: int, maxRetries?: int, retryDelay?: int}>> key: payload . "\0" . resource */
    private array $handlersByPayloadAndResource = [];

    /** @var array<string, array{class: string, payload?: string, resource?: string, execution: string, transport: ?string, queue: ?string, priority: int}> className => handler metadata */
    private array $handlersByClass = [];

    /**
     * Register a handler mapping. Called during boot only.
     *
     * @param array{class: string, payload?: string, resource?: string, execution: string, transport: ?string, queue: ?string, priority: int, maxRetries?: int, retryDelay?: int} $handlerMeta
     */
    public function register(string $payloadClass, string $resourceClass, array $handlerMeta): void
    {
        $key = $payloadClass . "\0" . $resourceClass;
        $this->handlersByPayloadAndResource[$key][] = $handlerMeta;
        $this->handlersByClass[$handlerMeta['class']] = $handlerMeta;
    }

    /**
     * Find handlers matching a request class and response class.
     * Matches via subclass hierarchy (responseClass may be a subclass of the handler's resource).
     *
     * @return list<array{class: string, execution: string, transport: ?string, queue: ?string, priority: int, maxRetries?: int, retryDelay?: int}>
     */
    public function findHandlers(string $requestClass, ?string $responseClass): array
    {
        if ($responseClass === null) {
            return [];
        }

        $found = [];
        foreach ($this->handlersByPayloadAndResource as $key => $handlers) {
            $parts = explode("\0", $key, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $payload = $parts[0];
            $resource = $parts[1];

            if ($resource !== $responseClass && !is_subclass_of($responseClass, $resource)) {
                continue;
            }
            if ($requestClass !== $payload && !is_subclass_of($requestClass, $payload)) {
                continue;
            }

            foreach ($handlers as $meta) {
                $found[] = $meta;
            }
        }

        return $found;
    }

    /**
     * Get all registered handler class names.
     *
     * @return list<string>
     */
    public function getHandlerClassNames(): array
    {
        return array_keys($this->handlersByClass);
    }

    /**
     * Get handler metadata by class name.
     *
     * @return array{class: string, payload?: string, resource?: string, execution: string, transport: ?string, queue: ?string, priority: int}|null
     */
    public function getHandlerByClass(string $className): ?array
    {
        return $this->handlersByClass[$className] ?? null;
    }

    /**
     * Every distinct payload class some handler claims to handle.
     *
     * Discovery uses this for its boot guard: a handler naming a payload that no
     * route ever produced is a wiring mistake that would otherwise surface as a
     * silently dead endpoint. Answering it here keeps the composite key format
     * private to this class — the caller no longer has to know that the map is
     * keyed by "payload\0resource".
     *
     * @return list<string>
     */
    public function payloadClasses(): array
    {
        $payloads = [];
        foreach (array_keys($this->handlersByPayloadAndResource) as $key) {
            $parts = explode("\0", $key, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $payloads[$parts[0]] = true;
        }

        return array_keys($payloads);
    }
}
