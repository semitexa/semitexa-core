# AsPayloadPart Attribute

## Description

`#[AsPayloadPart]` marks a **trait** as an extension part of a payload (request) DTO.  
Modules can provide such traits; the code generator merges them into the final request class when you run:

```bash
bin/semitexa request:generate <RequestShortName>
# or
bin/semitexa request:generate --all
```

The generated wrapper class lives in the module’s `Application/Payload/` and `use`s the base request plus all traits that target it.

## Usage

1. **Base request** (any module or vendor) with `#[AsPayload(...)]`:
   ```php
   #[AsPayload(path: '/features/json', methods: ['GET'])]
   final class FeaturesJsonRequest implements PayloadInterface {}
   ```

2. **Trait** in any module (e.g. `Semitexa\Modules\FeatureShowcase\Application\Payload\...`) with `#[AsPayloadPart(base: ...)]`:
   ```php
   use Semitexa\Core\Attributes\AsPayloadPart;

   #[AsPayloadPart(base: \Semitexa\Modules\Website\Application\Payload\FeaturesJsonPayload::class)]
   trait FeaturesJsonRequestTracking
   {
       public string $trackingId = '';
   }
   ```

3. **Regenerate** the request wrapper so it uses the trait:
   ```bash
   bin/semitexa request:generate FeaturesJsonRequest
   ```

The generated file will be in `src/modules/<Module>/Application/Payload/<Request>.php` with namespace `Semitexa\Modules\<Module>\Application\Payload` and will contain `use ... FeaturesJsonRequestTracking;` in the class body.

## Parameters

### Required

- `base` (string) – Fully-qualified class name of the payload/request this part extends.

### Optional

- `doc` (string|null) – Path to this doc file (relative to project root).

## Where to put traits

- **Any module** under `src/modules/<Name>/` with namespace `Semitexa\Modules\<Name>\...` (so the autoloader discovers them).
- Traits **must** be **traits**; classes with `#[AsPayloadPart]` are ignored by the generator.

## Requirements

1. The target class must be a **trait**.
2. `base` must be the exact FQN of an existing `#[AsPayload]` request class.
3. After adding or changing `#[AsPayloadPart]` traits, run `bin/semitexa request:generate <Request>` (or `--all`) to regenerate the wrapper.

## Related

- [AsPayload](AsPayload.md) – Base request DTO (alias/evolution of AsRequest).
- [ADDING_ROUTES.md](../ADDING_ROUTES.md) – New routes only in modules.
