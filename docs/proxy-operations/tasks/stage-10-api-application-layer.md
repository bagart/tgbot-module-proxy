# Stage 10 — API & application layer (plan tasks #62–70)

Source: `../plan.md` §§10.6–10.9, 11.15 п.1.

Scope: Application Commands/Queries (one application layer under bot/web/MiniApp/CLI);
API Resources + versioned additive-only contract; admin settings editable from
web admin and Telegram `/settings` over the same layer with audit; import/export
endpoints; masked secrets everywhere (never logged, INV-013 adjacent).

Exit: application services + HTTP/bot surfaces + tests green.
