# T94 — Invariant arch-tests INV-001…020 + ADR

Plan ref: task #94; plan §11.37 (Invariant Matrix), §11.39. Depends on: all Stage-0 tasks.

## Goal

Turn the Invariant Matrix into executable arch-tests and freeze Stage 0
decisions in an ADR.

## Create

- `docs/proxy-operations/adr/ADR-001-stage0-contracts.md`: consolidated ADR —
  identity model, failure taxonomy axis, lifecycle, wire contracts, tool
  boundary, cache key v3, snapshots, SSRF split; each with one-paragraph
  rationale + link to plan section.
- Arch-tests under `tests/Arch/InvariantsTest.php` (Pest). Pragmatic static
  checks over `src/`:
  - INV-003/INV-009: classes under `src/Wire`, `src/Tool` reference no PDO/
    Eloquent/Postgres symbols; no Redis client types outside adapters namespace
    (which does not exist yet → assert absence).
  - INV-011: `src/Tool/*Context*` signatures contain no `Domain` entity types
    (reflection over method parameters).
  - INV-012: `src/**` contains no `shell_exec|`\`backticks\`|proc_open with
    string command` usage (`grep`-style content assertion).
  - INV-013: no `argv` credential concatenation helpers: forbid string-building
    of credentials into command arrays (assert `CredentialChannel` is the only
    credential-passing abstraction referenced by Tool context).
  - INV-014/015: structural — `AuditResultV1` observations collection type
    cannot accept Checker-class FailureCodes (unit-level re-check here).
  - INV-016/017/018: wire DTOs carry SCHEMA_VERSION; cache key includes
    toolSemanticsVersion; ProbeTool is an interface consumed via registry.
  - INV-020: control-plane and execution-plane route enums are distinct types.
  - INV-001/002/004/005/006/007/008/010: documented as "enforced by later-stage
    tests" placeholders with explicit skip + reason where code does not exist yet.

## Acceptance

- All non-placeholder invariant tests green; placeholders explicitly marked.
- `composer test` green overall; ADR committed to docs tree.
