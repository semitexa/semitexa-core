<?php

declare(strict_types=1);

namespace Semitexa\Core\Contract;

/**
 * Response that supports layout/Twig rendering.
 * When the resource format is Layout, the framework passes an instance of this interface
 * to the handler so it can call setRenderHandle() and setRenderContext().
 */
interface LayoutRenderableInterface extends ResourceInterface
{
    public function setRenderHandle(string $handle): self;

    public function getRenderHandle(): ?string;

    public function setRenderContext(array $context): self;

    public function getRenderContext(): array;
}
