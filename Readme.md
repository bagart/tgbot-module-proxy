# telegram-bot-proxy-module

Proxy Operations module for the Telegram bot platform: proxy inventory,
auditing, health/lifecycle, pools, lease and verified export.

- Plan: `docs/proxy-operations/plan.md`
- Execution sub-plans: `docs/proxy-operations/tasks/README.md`

## Menu integration

Menu-hub surface per `telegram-bot-menu-module/docs/tasks/menu_integration.md` (M-6 slice 2):
`/proxy` command (private chats only, tenant = bot owner) and
`ProxyInventoryHandler` (`GET inventory`, tenant = hub user). Masked counts only —
hosts and credentials never cross the bridge. The ProxyUi Mini App chunk and the
§8.3 settings surface are pending (plan.md §10.12 item 22).
