# Payload validation

## Idea

Payload DTOs are the **shield** from external data: hydration, type casting, and validation happen in one place. Handlers work only with **clean, validated data**. Invalid input is **rejected** (422 Unprocessable Entity). All Payload DTOs (Request, Session, Cookie, etc.) follow the same rules: **protected** fields, access only via **getters/setters**, and **ValidatablePayload** required.

## Pipeline

1. **Hydrate** — `RequestDtoHydrator::hydrate($dto, $request)` fills the DTO using the **setter convention**: for each key in raw data (JSON/POST/query + path params), the hydrator calls `set{CamelCase}($value)` if the method exists. The value is cast to the setter’s parameter type before calling.
2. **Validate** — `PayloadValidator::validate($dto, $request)` calls **`$dto->validate()`**. Every Payload must implement `ValidatablePayload` and return a `PayloadValidationResult`. Validation logic lives in the DTO (e.g. using validation traits).
3. If validation fails → return **422** with a body listing field errors; handler is **not** called.
4. If validation passes → handler runs with a DTO that is guaranteed to satisfy your rules.

## Payload rules

- **Fields:** `private` or `protected` only.
- **Access:** only through **getters** (`get*`) and **setters** (`set*`).
- **Interface:** implement `Semitexa\Core\Contract\ValidatablePayload` with `validate(): PayloadValidationResult`.
- **Validation:** implement `validate()` (e.g. by using validation traits and collecting errors).

## Validation traits (core)

Use these traits in your Payload and call their methods from `validate()`:

- **`NotBlankValidationTrait`** — `$this->validateNotBlank('fieldName', $this->fieldName, $errors);`
- **`EmailValidationTrait`** — `$this->validateEmail('fieldName', $this->fieldName, $errors);`
- **`LengthValidationTrait`** — `$this->validateLength('fieldName', $this->fieldName, $min, $max, $errors);`

Namespaces: `Semitexa\Core\Validation\Trait\*`. You can add your own traits in modules and reuse them across Payloads.

## Example

```php
use Semitexa\Core\Contract\PayloadInterface;
use Semitexa\Core\Contract\ValidatablePayload;
use Semitexa\Core\Http\PayloadValidationResult;
use Semitexa\Core\Validation\Trait\EmailValidationTrait;
use Semitexa\Core\Validation\Trait\NotBlankValidationTrait;
use Semitexa\Core\Validation\Trait\LengthValidationTrait;

#[AsPayload(path: '/contact', methods: ['GET', 'POST'], responseWith: ContactFormResource::class)]
class ContactFormPayload implements PayloadInterface, ValidatablePayload
{
    use EmailValidationTrait, NotBlankValidationTrait, LengthValidationTrait;

    protected string $email = '';
    protected string $message = '';

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): void { $this->email = $email; }
    public function getMessage(): string { return $this->message; }
    public function setMessage(string $message): void { $this->message = $message; }

    public function validate(): PayloadValidationResult
    {
        $errors = [];
        $this->validateNotBlank('email', $this->email, $errors);
        $this->validateEmail('email', $this->email, $errors);
        $this->validateLength('message', $this->message, null, 5000, $errors);
        return new PayloadValidationResult(empty($errors), $errors);
    }
}
```

## Hydration (setter convention)

- Data keys (e.g. `email`, `flash_message`) are converted to setter names: `setEmail`, `setFlashMessage` (snake_case → camelCase).
- Path params from the route (e.g. `{id}`) are passed as key `id` and trigger `setId($value)`.
- The hydrator uses the setter’s parameter type to cast the value before calling.

## Response on validation failure

- **Status:** 422 Unprocessable Entity.
- **Body (e.g. JSON):** `{ "errors": { "fieldName": ["message1", "message2"], ... } }`.

## Session / Cookie payloads

The same rules apply: protected fields, getters/setters, `ValidatablePayload`. Session and queue payloads are serialized with `DtoSerializer`, which uses getters for `toArray()` and setters for `hydrate()`.
