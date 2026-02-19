# Attributes Documentation

This directory contains documentation for all attributes used in the Semitexa framework.

## Structure

Attributes can optionally reference a documentation file via the `doc` parameter:

```php
#[AsRequest(
    doc: 'docs/attributes/AsRequest.md',  // optional: link to doc (relative to project root)
    path: '/api/users',
    methods: ['GET']
)]
class UserListRequest implements PayloadInterface {}
```

When framework docs live in `packages/semitexa/`, use a path relative to the **application** root (e.g. `packages/semitexa/core/docs/attributes/AsRequest.md`).

## Available attributes

Request/Handler classes must be in **modules** (`src/modules/`, `packages/`, or `vendor/`); classes in project `src/` (namespace `App\`) are **not** discovered for routes. See [ADDING_ROUTES.md](../ADDING_ROUTES.md).

### Core Attributes

- [AsRequest](AsRequest.md) - HTTP Request DTO
- [AsRequestHandler](AsRequestHandler.md) - HTTP Request Handler
- [AsResponse](AsResponse.md) - HTTP Response DTO
- [AsPayloadPart](AsPayloadPart.md) - Payload/Request extension trait (codegen merges into wrapper)

### ORM Attributes

- [AsEntity](AsEntity.md) - Database Entity
- [AsEntityPart](AsEntityPart.md) - Storage entity extension trait
- [AsDomainPart](AsDomainPart.md) - Domain model extension trait
- [Column](Column.md) - Database column mapping
- [Id](Id.md) - Primary key
- [GeneratedValue](GeneratedValue.md) - Auto-generated value
- [TimestampColumn](TimestampColumn.md) - Timestamp columns

## Creating new attribute documentation

When creating a new attribute:

1. Create a documentation file in `docs/attributes/` (or `packages/semitexa/core/docs/attributes/` in monorepo).
2. Optionally add a `doc` parameter to the attribute constructor to reference the file.
3. Fill the documentation with usage and examples.

### Documentation template

```markdown
# AttributeName

## Description

Short description of what the attribute does.

## Usage

```php
// Example code
```

## Parameters

### Required
- `param` - Description

### Optional
- `param` - Description

## Examples

### Basic example
```php
// Code
```

## Requirements

1. Requirement 1
2. Requirement 2

## Related attributes

- [OtherAttribute](OtherAttribute.md)

## See also

- [Related Documentation](../README.md)
```

## Benefits

✅ **For developers**: Documentation is close to the code when `doc` is set  
✅ **For IDEs**: Enables autocomplete and hints based on documentation
