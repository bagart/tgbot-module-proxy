# Stage 7 — Pools, selection, lease (plan tasks #45–52)

Source: `../plan.md` §§11.21–11.26, 11.35 пп.4–6.

Scope: pool = projection of healthy accesses; Selector with decision log;
Lease with recovery protocol (expiry, steal, renewal); VerifiedEligibilityPolicy
consumed here. Append-only observations underpinning selection. All queries
tenant-scoped.

Exit: selector + lease service + decision log + tests incl. crash-recovery cases.
