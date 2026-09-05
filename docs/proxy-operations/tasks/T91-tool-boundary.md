# T91 — External Tool boundary: ProbeTool contract, Registry, Governor

Plan ref: task #91; plan §11.39 (whole). Depends on: T90.
Safe to run in parallel with T92.

## Goal

Contract layer that keeps external binaries replaceable and contained
(INV-011/012/013/018/019). Contracts only — no real runners/binaries yet.

## Create under `src/Tool/`

- `ToolId` value object (validated slug).
- `ProbeTool` interface: `capabilities(): ToolCapabilities`,
  `execute(ProbeExecutionContext $context): ProbeToolResult`.
- `ToolCapabilities` readonly DTO: supported probe types, protocol versions,
  input/output schema versions.
- `ProbeExecutionContext`: **minimal ProbeInput** per §11.39 п.5 — endpoint
  (host/port), credential channel handle (**never raw credentials in a
  constructor-exposed string field; delivered via `CredentialChannel`
  abstraction — stdin/FD plan**), probe spec, limits. No domain entities
  (no AccessIdentity/Tenant/AuditJob types on its API surface).
- `ProbeToolResult`: status ok/failure, timings, raw observations array,
  or `ExecutionFailure` descriptor for tool-level failures.
- `ToolManifest` readonly DTO + `fromJson(array)`: name, version,
  apiVersion, capabilities, limits (`maxExecutionTime`, `maxOutputBytes`),
  security block (network/filesystem/privileges).
- `ToolRegistry`: allowlist `toolId → manifest`; `resolve(ToolId)` throws
  `UnknownToolException`; no path/executable strings accepted from callers
  (INV-012).
- `ResourceGovernorSpec` readonly DTO: maxConcurrentProbes, maxProcesses,
  maxMemoryBytes, maxCpuPercent, maxExecutionTimeSeconds, maxOutputBytes,
  maxStdinBytes, maxFileDescriptors.
- `ProbeRunnerTransport` enum: `UnixSocket`, `HttpsMtls`.
- `ControlPlaneRoute` / `ExecutionPlaneRoute` enums: control =
  health/ready/metrics/capabilities; execution = create/get/cancel execution
  (§11.39 п.4 / INV-020).

## Tests

- Registry rejects unknown tool id; manifests validate (limits positive).
- ProbeExecutionContext surface contains no domain entity types (reflection test).
- Transport/plane enums cover plan §11.39 values.
