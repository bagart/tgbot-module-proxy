# T89 — Lifecycle state machine + evidence types

Plan ref: task #89; plan §11.6, §11.35 пп.9–11, R6.3. Depends on: T88.
Safe to run in parallel with T93.

## Goal

One canonical access lifecycle state machine plus typed, dimension-specific
evidence contracts feeding it. Health/lifecycle/quarantine belong to
ProxyAccess (INV-001).

## Create under `src/Domain/Lifecycle/`

- `AccessState` enum with the plan §11.6 states (`New`, `Testing`, `Healthy`,
  `Degraded`, `Suspect`, `Quarantined`, `Dead`, `Retired` — align exactly with
  plan table; adjust names to what plan actually defines).
- `LifecycleEvent` DTO: from → to transition + reason FailureCode|null + timestamp.
- `TransitionRule` + `AccessStateMachine`: allowed transitions map from plan;
  `transition(AccessState $from, FailureCode|HealthSignal $cause): LifecycleEvent`;
  throws on illegal transition.
- Evidence side under `src/Domain/Evidence/`:
  - `EvidenceType` enum: `Tcp`, `Http`, `Tls`, `Dns`, `Udp`, `Judge`, `Telegram`,
    `Bandwidth`.
  - `DimensionEvidence` interface + per-dimension readonly DTOs carrying raw,
    tenant-neutral measurements (latency ms, success bool, failure descriptor).
  - `EvidenceApplicability`: which evidence types apply to which capability
    dimensions (per plan §11.35 пп.1–2: no dimension without applicable
    evidence; no evidence without a consumer).
- `VerifiedEligibilityPolicy` contract (interface): decides whether an access is
  eligible for the verified projection given telegram freshness rules
  (plan §11.35 пп.9–11: telegram freshness window, recheck requirement).
- `HysteresisPolicy` support: thresholds/guards against flapping (plan §11.13
  гистерезис в lifecycle/incident policy).

## Tests

- Full legal-transition matrix green; each illegal transition throws.
- Evidence applicability matrix consistent (no orphan dimensions).
- Telegram freshness: stale telegram evidence blocks verified eligibility.
