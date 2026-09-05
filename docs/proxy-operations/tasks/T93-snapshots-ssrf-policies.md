# T93 — Versioned snapshots + SSRF policy split

Plan ref: task #93; plan §11.35 пп.7–8, 13–14. Depends on: T88.
Safe to run in parallel with T89/T91/T92.

## Goal

Immutable, versioned context snapshots embedded into audit jobs so results are
reproducible and auditable; SSRF guard configuration as three explicit,
separately-governed policies.

## Create under `src/Domain/Snapshot/`

- `JudgeSetSnapshot`: judge set id, version, judge descriptors (id, url class,
  trust tier), frozenAt. Judge trust model per plan §11.8 (HMAC trust only for
  self-hosted judges).
- `TelegramDcSet`: DC list snapshot with version (source of truth for telegram
  connectivity probes).
- `AuditPolicySnapshot` readonly DTO embedding the effective audit policy for a
  job (thresholds, probe profile mapping, quarantine rules) + `schemaVersion`.
  Separation rule from plan: workspace-editable settings live in
  WorkspacePolicy; the snapshot is the immutable copy referenced by jobs.
- `ContractVersionMatrix` data holder: contract name → current version +
  compatible versions (worker/parser/verified contracts, plan §11.12).

Under `src/Domain/Policy/`:

- SSRF policy trio (plan §11.35 пп.7–8) as three separate readonly DTOs:
  - `ProxyEndpointConnectPolicy` — what the checker may connect to when probing
    a proxy endpoint (denylist private/metadata IPv4+IPv6, resolve-then-connect),
  - `JudgeConnectPolicy` — fixed judges only,
  - `TargetFetchPolicy` — allowed fetch targets for liveness/content probes.
- Shared validation support: `IpDenylist` (v4+v6 ranges incl. link-local,
  ULA, metadata 169.254.169.254), resolved-IP checking helper contract
  (`ResolvedTargetChecker` interface — anti-DNS-rebinding hook).

## Tests

- Snapshot round-trips + version fields mandatory.
- AuditPolicySnapshot is immutable copy semantics (mutating source does not
  change snapshot).
- Denylist vectors: 127.0.0.0/8, 10/8, ::1, fc00::/7, fe80::/10,
  169.254.169.254 rejected by each policy where applicable.
