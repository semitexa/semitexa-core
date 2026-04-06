# Payload validation

## Idea

Payload DTOs are the boundary between external data and application code. Semitexa already hydrates payloads through typed setters, so field ownership and field-level validation should live in those setters instead of in one DTO-wide `validate()` method.

Invalid input is still rejected as `422 Unprocessable Entity`, but the rejection should be expressed by the field that failed, not by a monolithic post-hydration pass.

## Pipeline

1. **Hydrate** - `PayloadHydrator::hydrate($dto, $request)` fills the DTO using the setter convention: for each key in raw data (JSON/POST/query + path params), the hydrator calls `set{CamelCase}($value)` if the method exists.
2. **Normalize and guard** - each setter can normalize its input and reject invalid values immediately with a field-aware exception such as `Semitexa\Core\Exception\ValidationException`.
3. **Return 422 on failure** - `RouteExecutor` or the surrounding request pipeline converts the field error into an HTTP 422 response before the handler runs.
4. **Handle** - the handler receives a DTO whose individual fields have already been normalized and guarded by their own setters.

## Payload rules

- **Fields:** `private` or `protected` only.
- **Access:** only through **getters** (`get*`) and **setters** (`set*`).
- **Validation ownership:** field-level rules belong in the setter for that field.
- **Cross-field rules:** keep them explicit and local. If a rule spans multiple fields, prefer a dedicated helper method or a small field-specific exception path over a hidden DTO-wide validation bag.

## Validation helpers

Core still provides reusable building blocks for teams that want shared validation logic:

- `Semitexa\Core\Exception\ValidationException` for structured field errors
- the existing validation traits in `Semitexa\Core\Validation\Trait\*` for shared rule helpers

These helpers are still useful, but they no longer imply that every payload must funnel all rules through `validate()`.

## Example

```php
use Semitexa\Core\Attribute\AsPayload;
use Semitexa\Core\Exception\ValidationException;

#[AsPayload(path: '/contact', methods: ['POST'], responseWith: ContactFormResource::class)]
class ContactFormPayload
{
    protected string $email = '';
    protected string $message = '';

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $email = trim($email);

        if ($email === '') {
            throw new ValidationException(['email' => ['Email is required.']]);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(['email' => ['Email must be valid.']]);
        }

        $this->email = $email;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): void
    {
        $message = trim($message);

        if ($message === '') {
            throw new ValidationException(['message' => ['Message is required.']]);
        }

        if (mb_strlen($message) > 5000) {
            throw new ValidationException(['message' => ['Message must stay below 5000 characters.']]);
        }

        $this->message = $message;
    }
}
```

## Hydration

- Data keys such as `email` and `flash_message` are converted to setter names such as `setEmail` and `setFlashMessage`.
- Path params from the route, such as `{id}`, are passed as key `id` and trigger `setId($value)`.
- The hydrator uses the setter's parameter type to cast the value before calling it.

## Response on validation failure

- **Status:** 422 Unprocessable Entity.
- **Default Core body example:** `{ "error": "validation_exception", "message": "The given data was invalid.", "context": { "errors": { "fieldName": ["message1", "message2"], ... } } }`.

Setter-thrown `ValidationException` is the field-level signal used by this model, and the Core route pipeline maps it to the 422 envelope above.

## Session / Cookie payloads

The same rules apply: protected fields, getters/setters, and explicit field guards. Session and queue payloads are serialized with `PayloadSerializer`, which uses getters for `toArray()` and setters for `hydrate()`.

## Authoring note

`validate()` is not the authoring model for new payloads. New payloads should prefer setter-owned validation and should keep field normalization, field-shape checks, and cross-field guards explicit in the payload itself.
