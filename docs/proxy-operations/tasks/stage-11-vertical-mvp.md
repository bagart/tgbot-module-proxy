# Stage 11 — Vertical MVP (plan tasks #71–79)

Source: `../plan.md` §11.14 Stage 11.

Scope: end-to-end `/import → Parser → Inventory → Audit → Health → Pool →
Lease → Export` through Bot + Mini App + Web + CLI. TgModuleContract plugin
registration, module provider self-registration into
`config('telegram.modules_providers')`, i18n five languages (RU/EN/FR/ES/ZH,
all strings via keys), dual-mode packaging readiness (dev PSR-4 map +
composer.prod.json entry once repo published).

Exit: vertical slice demo-able; `composer test` across suites green.
