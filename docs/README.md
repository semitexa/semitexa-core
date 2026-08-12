# Semitexa Core Docs

This directory is **not** the canonical documentation for `semitexa/core`.
Documentation lives in `semitexa/docs`, filed by subject rather than by the
package that implements it — see its `workspace/DOCUMENTATION_OWNERSHIP.md`.
What remains here is being moved there; nothing new belongs in this directory.

The attribute reference that used to live in `docs/attributes/` is gone. It is
generated now, from the attribute classes themselves, by
`bin/semitexa docs:reference:generate` — signature, targets, parameters and a
usage quoted from the codebase. Five of its seven pages documented attributes
that no longer exist (`AsEntity`, `AsPayload`, `AsRequest`, `AsRequestHandler`,
`AsDomainPart`), and the two that were real described them alongside a
surrounding API that is not: `BaseEntity`, a `doc:` parameter,
<!-- docs-lint-ignore -->
`#[AsEntity]`.
Hand-written reference is the kind that rots first, because a signature changes
without anybody thinking of the page.

## Start Here

- `ADDING_ROUTES.md`
- `MODULE_STRUCTURE.md`
- `RUNNING.md`
- `SERVICE_CONTRACTS.md`
- `RELEASE_NOTES.md`
- `PAYLOAD_VALIDATION.md`

If a root-level document repeats one of these topics, treat the root document as a wrapper or navigation page unless it explicitly defines additional repository-specific constraints.
