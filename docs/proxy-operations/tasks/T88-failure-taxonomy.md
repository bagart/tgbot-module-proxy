# T88 — Failure taxonomy V1 + ProbeProfile

Plan ref: task #88; plan §§11.16–11.17, §11.39 пп.12–14. Depends on: T87.

## Goal

Single failure vocabulary with an explicit responsibility axis, so that checker
infrastructure failures can never be misread as proxy failures (INV-014/015).

## Create under `src/Domain/Failure/`

- `FailureClass` enum: `Proxy`, `Target`, `Judge`, `Checker`, `Platform`, `Policy`.
- `FailureCode` enum (backed string) covering plan §11.16 table: TCP_TIMEOUT,
  TCP_REFUSED, AUTH_FAILURE, TLS_FAILURE, MTPROTO_HANDSHAKE_FAILED,
  TARGET_4XX, TARGET_5XX, JUDGE_UNAVAILABLE, JUDGE_INCONSISTENT,
  TOOL_TIMEOUT, TOOL_CRASH, TOOL_PROTOCOL_ERROR, TOOL_OOM, TOOL_EXIT_FAILURE,
  TOOL_OUTPUT_INVALID, TOOL_UNAVAILABLE, REDIS_UNAVAILABLE,
  STORAGE_UNAVAILABLE, SSRF_BLOCKED, UNSUPPORTED_PROTOCOL.
- `FailureDescriptor` readonly DTO: code → class + attributes
  `retryable`, `countsAsFailure`, `affectsHealth`, `affectsCapability`,
  `quarantineAfterThreshold` (bool each). Central registry
  `FailureTaxonomy`: complete mapping per plan §11.16 (TARGET_* soft on health;
  TOOL_*/REDIS_*/STORAGE_* never affect proxy health or capability;
  SSRF_BLOCKED/UNSUPPORTED_PROTOCOL = policy rejections).
- `ExecutionFailure` vs `ProxyFailure` value objects (both wrap descriptor +
  context array); factory methods on the registry produce the right type by
  FailureClass.
- `ProbeProfile` enum: `Light`, `Standard`, `Deep`, `Telegram`, `Bandwidth`.
- `ProbeProfileDefinition`: profile → probe set (probe type identifiers as
  strings/enums), expected cost tier; pure data.

## Tests

- Every FailureCode has a descriptor with all attributes; TOOL_* codes are
  class Checker and `affectsHealth=false`, `countsAsFailure=false` for the proxy;
  TARGET_4XX/5XX `affectsCapability=false`.
- Execution vs Proxy failure typing by class.
- ProbeProfile sets match plan §11.17 rows.
