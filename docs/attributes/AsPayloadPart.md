# AsPayloadPart Attribute

## Description

`#[AsPayloadPart]` marks a **trait** as an additive extension of an existing payload DTO.

Another module can target a base `#[AsPayload(...)]` class and contribute extra typed setters, getters, and transport-boundary logic without reopening or forking the original payload class.

That includes validation ownership. If the trait adds a field, the trait should also own the setter-level guard for that field instead of pushing the rule back into a base-class `validate()` method.

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
        $query = trim($query);

        if ($query === '') {
            throw new \Semitexa\Core\Exception\ValidationException([
                'query' => ['Search query is required.'],
            ]);
        }

        $this->query = $query;
    }
}
```

2. **Trait** in another module:

```php
use Semitexa\Core\Attribute\AsPayloadPart;

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
        if ($campaign !== null && strlen($campaign) > 64) {
            throw new \Semitexa\Core\Exception\ValidationException([
                'campaign' => ['Campaign code must stay below 64 characters.'],
            ]);
        }
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
- Field-level validation for the added concerns should live with the added setters, which keeps the base payload closed for modification.
- Composition happens at runtime through `PayloadFactory`; there is no separate payload code-generation step.

## Related

- [AsPayload](AsPayload.md) – Base payload DTO
- [ADDING_ROUTES.md](../ADDING_ROUTES.md) – Route discovery and payload registration
