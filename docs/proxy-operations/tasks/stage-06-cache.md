# Stage 6 — Raw probe cache (plan tasks #41–44)

Source: `../plan.md` §§11.7, 11.19–11.20.

Scope: ProbeCacheKeyV3-backed shared cache (raw evidence allowlist only,
INV-005), CachePolicy (TTL per probe kind, negative caching), cache-aware
probe planner in the worker, metrics on hit/miss. Shared cache ON at this stage
per plan §11.14.

Exit: cache service + policy + tests green.
