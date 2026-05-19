<?php

declare(strict_types=1);

namespace Semitexa\Core\Http\Response;

use Semitexa\Core\Contract\LayoutRenderableInterface;
use Semitexa\Core\Contract\ResourceInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\HttpResponse as CoreResponse;

class ResourceResponse implements ResourceInterface, LayoutRenderableInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private string $content = '',
        private int $statusCode = HttpStatus::Ok->value,
        private array $headers = []
    ) {
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setStatusCode(int $statusCode): self
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    private bool $alreadySent = false;

    /**
     * Mark this response as fully delivered to the transport.
     *
     * Used by handlers that take exclusive ownership of the raw Swoole
     * Response (e.g. SseKissHandler streams headers + chunks + end() before
     * returning). SwooleResponseEmitter sees this flag and skips its own
     * status/header/end emission — preventing a double-end SIGSEGV on the
     * same underlying Swoole Response.
     */
    public function markAsAlreadySent(): self
    {
        $this->alreadySent = true;
        return $this;
    }

    public function isAlreadySent(): bool
    {
        return $this->alreadySent;
    }

    public function toCoreResponse(): CoreResponse
    {
        return new CoreResponse($this->content, $this->statusCode, $this->headers, $this->alreadySent);
    }

    // Redirect support
    private ?string $redirectUrl = null;

    public function setRedirect(string $url, int $statusCode = HttpStatus::Found->value): self
    {
        $this->redirectUrl = $url;
        $this->statusCode = $statusCode;
        return $this;
    }

    public function getRedirectUrl(): ?string
    {
        return $this->redirectUrl;
    }

    // Render pipeline hints (optional)
    private ?string $renderHandle = null;
    private ?string $layoutFrame = null;
    /** @var array<string, mixed> */
    private array $renderContext = [];
    private ?\Semitexa\Core\Http\Response\ResponseFormat $renderFormat = null;
    private ?string $rendererClass = null;

    public function setRenderHandle(string $handle): self
    {
        $this->renderHandle = $handle;
        return $this;
    }

    public function getRenderHandle(): ?string
    {
        return $this->renderHandle;
    }

    /** Optional layout frame (e.g. one-column, two-columns-left) for layout-level slots. */
    public function setLayoutFrame(?string $layoutFrame): self
    {
        $this->layoutFrame = $layoutFrame;
        return $this;
    }

    public function getLayoutFrame(): ?string
    {
        return $this->layoutFrame;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function setRenderContext(array $context): self
    {
        $this->renderContext = $context;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRenderContext(): array
    {
        return $this->renderContext;
    }

    /**
     * Alias for setRenderContext for convenience
     */
    /**
     * @param array<string, mixed> $context
     */
    public function setContext(array $context): self
    {
        return $this->setRenderContext($context);
    }

    /**
     * Alias for getRenderContext for convenience
     */
    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->getRenderContext();
    }

    public function setRenderFormat(?\Semitexa\Core\Http\Response\ResponseFormat $format): self
    {
        $this->renderFormat = $format;
        return $this;
    }

    public function getRenderFormat(): ?\Semitexa\Core\Http\Response\ResponseFormat
    {
        return $this->renderFormat;
    }

    public function setRendererClass(?string $rendererClass): self
    {
        $this->rendererClass = $rendererClass;
        return $this;
    }

    public function getRendererClass(): ?string
    {
        return $this->rendererClass;
    }
}

