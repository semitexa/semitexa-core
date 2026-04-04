# AsPayloadPart Attribute

## Description

`#[AsPayloadPart]` marks a **trait** as an additive extension of an existing payload DTO.

Another module can target a base `#[AsPayload(...)]` class and contribute extra typed setters, getters, and transport-boundary logic without reopening or forking the original payload class.

At runtime, Semitexa discovers all matching payload-part traits and composes a wrapper class that:

- extends the base payload
- uses all discovered traits targeting that payload
- is cached per worker by `PayloadFactory`

The handler still receives one payload object. The transport boundary stays singular even when multiple modules extend it.

## Usage

1. **Base payload** in one module:

```php
#[AsPayload(path: '/search', methods: ['GET'])]
final class SearchPayload
{
    protected string $query = '';

    public function getQuery(): string
    {
        return $this->query;
    }

    public function setQuery(string $query): void
    {
        $this->query = trim($query);
    }
}
```

2. **Trait** in another module:

```php
use Semitexa\Core\Attributes\AsPayloadPart;

#[AsPayloadPart(base: SearchPayload::class)]
trait SearchTrackingPart
{
    protected ?string $campaign = null;

    public function getCampaign(): ?string
    {
        return $this->campaign;
    }

    public function setCampaign(?string $campaign): void
    {
        $campaign = $campaign !== null ? trim($campaign) : null;
        $this->campaign = $campaign !== '' ? $campaign : null;
    }
}
```

3. **Handler** receives the composed payload transparently:

```php
final class SearchHandler implements TypedHandlerInterface
{
    public function handle(SearchPayload $payload, SearchPageResource $resource): SearchPageResource
    {
        return $resource
            ->withQuery($payload->getQuery())
            ->withCampaign($payload->getCampaign());
    }
}
```

## Parameters

### Required

- `base` (string) – Fully-qualified class name of the payload this trait extends.

### Optional

- `doc` (string|null) – Path to this documentation file.

## Requirements

1. The target must be a **trait**.
2. `base` must point to an existing payload class.
3. The trait must be autoload-discoverable by the active application.

## Notes

- `#[AsPayloadPart]` is about **modular transport-boundary extension**, not inheritance hacks.
- This is especially useful when one module owns the route, but another module must add request concerns such as tracking, preview flags, tenant hints, or feature toggles.
- Composition happens at runtime through `PayloadFactory`; there is no separate payload code-generation step.

## Related

- [AsPayload](AsPayload.md) – Base payload DTO
- [ADDING_ROUTES.md](../ADDING_ROUTES.md) – Route discovery and payload registration
