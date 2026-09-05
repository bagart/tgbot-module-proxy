# Stage 4 — Checker engine (plan tasks #25–30)

Source: `../plan.md` §§11.8, 11.16–11.17, 11.30, 11.39.

Scope: worker-side execution layer implementing Stage-0 contracts:
ProbeProfile-driven ProbeExecutor, JudgeProvider (JudgeSetSnapshot), first
ProbeTool implementations (Tcp/Tls/Http via transport; Socks), Observation
Normalizer producing tenant-neutral raw evidence. Worker = orchestration only,
Redis-only runtime (INV-003/009); ExecutionFailure vs ProxyFailure split at the
result boundary. Resource governor enforcement hooks.

Exit: executor + tools + normalizer + tests green.
