# Stage 5 — Audit pipeline (plan tasks #31–40)

Source: `../plan.md` §§11.9–11.10, 11.27–11.30, 11.35 пп.4–6.

Scope: AuditJob(+trigger,+AuditPolicySnapshot) → Attempt → Worker delivery
(idempotency split from T92) → Wire contracts over Redis Streams → results →
Observations → Health evaluation (hysteresis) → Lifecycle transitions → Events
(EventEnvelope + transactional ordering). Scheduler owns WHEN; worker never
makes domain decisions. DLQ + retry budgets per platform conventions.

Exit: end-to-end job→observation→state-change flow with feature tests incl.
failure injection.
