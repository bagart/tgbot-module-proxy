# Stage 1 — Core domain (plan tasks #1–15)

Refine into per-task sub-files before execution. Source: `../plan.md` §§4–6, 9.

Scope: Eloquent models + migrations for Workspace (tenant), ProxyEndpoint,
ProxyCredential, ProxyAccess, ProxySource, Capability, Observation, Health,
Policy. Tenant scoping mandatory on every domain table/query (defense-in-depth:
global scope + policy checks); `tenant_id` from authenticated workspace only
(INV-006). Encrypted credential storage (KEK/DEK separation, INV-004);
parser never encrypts (INV-007). Access owns health/lifecycle/quarantine
(INV-001/002). Factories for every model. Feature tests include negative
tenant-scoping cases.

Exit: models+migrations+factories, tenancy tests green.
