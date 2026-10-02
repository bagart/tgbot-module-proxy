<?php

declare(strict_types=1);

return [
    /*
    | Reserved for tenant/workspace resolution settings (T02). The platform
    | rule is 1 user = 1 workspace; nothing to configure yet.
    */
    'tenancy' => [],

    'encryption' => [
        // Env is read once here, at the config layer; domain code must use
        // config()/injected DTOs only. Consumed by T09 (envelope encryption).
        // Key Encryption Key material (plan §10.12 п.13); wraps per-workspace
        // DEKs only — field values are never touched with the KEK directly.
        'kek' => env('PROXY_ENC_KEY'),
        // SECURITY WARNING: fallback_to_app_key uses Laravel's APP_KEY as
        // encryption key when PROXY_ENC_KEY is not set. This is ONLY safe
        // in local/testing environments. In production, set PROXY_ENC_KEY
        // explicitly — the app key is shared across the entire application
        // and using it for proxy encryption reduces isolation guarantees.
        'fallback_to_app_key' => true,
        'algorithm' => 'aes-256-gcm',
        // Monotonic KEK version stamped into new envelopes (plan §11.23);
        // bump together with moving the previous material into historical_keks.
        'key_version' => env('PROXY_ENC_KEY_VERSION', 'k1'),
        // Previous KEK materials keyed by their version; used to unwrap DEKs
        // wrapped before a KEK rotation until rewrapDek() sweeps them over.
        'historical_keks' => [],
        // HMAC key for CredentialFingerprint (plan §11.37 R6.2): derived to a
        // fixed-length binary key by the model; falls back to the app key in
        // dev/test when fallback_to_app_key is enabled.
        // SECURITY: Same warning as above — set PROXY_FINGERPRINT_KEY in production.
        'fingerprint_key' => env('PROXY_FINGERPRINT_KEY'),
    ],

    // Default per-workspace limits placeholder (consumed by T07/T08).
    'quotas' => [],

    // Resource governor limits (plan §11.39 п.15, INV-019).
    // In-process limits only — container-level limits enforced by Docker.
    'resource_governor' => [
        'max_concurrent_probes' => 50,
        'max_processes' => 100,
        'max_memory_bytes' => 512 * 1024 * 1024,
        'max_cpu_percent' => 80,
        'max_execution_time_seconds' => 30,
        'max_output_bytes' => 1024 * 1024,
        'max_stdin_bytes' => 1024 * 1024,
        'max_file_descriptors' => 256,
    ],

    // "No deletion" decision (plan §11.15 п.6): retention off by default.
    'retention' => [
        'enabled' => false,
    ],

    // Audit job placement (T24; plan §§11.17, 11.18). The JobStarter freezes
    // these system defaults into every PolicySnapshot; WorkspacePolicy fields
    // (retention/quotas/UI/export) deliberately never enter a snapshot.
    'audit' => [
        // Placement idempotency window: a duplicate of
        // (tenant, trigger, target_set_hash, policy_snapshot_id) inside this
        // TTL is not created again — the existing job is returned.
        'placement_ttl_seconds' => 300,
        // Trigger → probe profile schedule (§11.17 table).
        'probe_profile_mapping' => [
            'manual' => 'standard',
            'scheduled' => 'light',
            'import' => 'light',
            'feed' => 'light',
            'lazy_selection' => 'light',
            'recovery' => 'standard',
            'tg_check' => 'telegram',
        ],
        // Lifecycle/health thresholds frozen into the snapshot (§11.6
        // hysteresis counters; consumed by the health-evaluation pipeline).
        'lifecycle_thresholds' => [
            'working_after_successes' => 3,
            'degraded_after_failures' => 2,
            'failing_after_failures' => 5,
            'dead_after_failures' => 10,
        ],
        // Failure code → consecutive-failure count that triggers quarantine.
        'quarantine_rules' => [
            'AUTH_FAILURE' => 2,
        ],
        // Health evaluation + lifecycle transitions (T27; §§11.6, 11.35
        // пп.9–10, §11.29 IMPROVE#11, R6.6). Hysteresis thresholds mirror
        // lifecycle_thresholds above in the per-direction form consumed by
        // the domain HysteresisPolicy; counters persist on proxy_accesses
        // (consecutive_failures / consecutive_successes) so anti-flap state
        // survives worker restarts.
        'health' => [
            // R6.6: stored next to every derived value on proxy_health.
            'health_formula_version' => 'dimensional-v1',
            // §11.35 п.10: telegram_usable freshness TTL; an expired flag is
            // never reported usable even when the last check succeeded.
            'telegram_freshness_seconds' => 21600,
            'hysteresis' => [
                'consecutive_failures_to_degrade' => 2,
                'consecutive_failures_to_failing' => 5,
                'consecutive_failures_to_declare_dead' => 10,
                'consecutive_successes_to_leave_failing' => 2,
                'consecutive_successes_to_leave_degraded' => 3,
                // Dwell guard between flips. Evaluation cadence is probe-
                // scheduled (well above the flap frequency), so the default
                // keeps the guard available without gating test throughput.
                'min_seconds_between_transitions' => 0,
            ],
        ],
        // Event catalog (T28; plan §11.20): event type → class (domain /
        // integration / operational), aggregate type, schema version. The
        // EventTypeRegistry is built from this map and the recorder fails
        // closed on any type outside it.
        'events' => [
            'registry' => [
                [
                    'type' => 'access.state_changed',
                    'class' => 'domain',
                    'aggregate_type' => 'proxy_access',
                    'schema_version' => 1,
                ],
                [
                    'type' => 'quarantine.changed',
                    'class' => 'domain',
                    'aggregate_type' => 'proxy_access',
                    'schema_version' => 1,
                ],
                [
                    'type' => 'audit.completed',
                    'class' => 'integration',
                    'aggregate_type' => 'proxy_access',
                    'schema_version' => 1,
                ],
                [
                    'type' => 'pool.rebuilt',
                    'class' => 'domain',
                    'aggregate_type' => 'proxy_pool',
                    'schema_version' => 1,
                ],
                [
                    'type' => 'lease.acquired',
                    'class' => 'domain',
                    'aggregate_type' => 'proxy_lease',
                    'schema_version' => 1,
                ],
                [
                    'type' => 'lease.released',
                    'class' => 'domain',
                    'aggregate_type' => 'proxy_lease',
                    'schema_version' => 1,
                ],
                [
                    'type' => 'worker.failed',
                    'class' => 'operational',
                    'aggregate_type' => 'proxy_audit_attempt',
                    'schema_version' => 1,
                ],
                [
                    'type' => 'judge.unavailable',
                    'class' => 'operational',
                    'aggregate_type' => 'judge',
                    'schema_version' => 1,
                ],
            ],
        ],
        // Shared raw-probe cache (T29; plan §§11.7, 11.14, R6.2/R6.4).
        // Values are SharedCacheValue DTOs only (INV-005); secrets never
        // enter keys or values (R6.2). Shared cache is ON at stage 6 (§11.14).
        'cache' => [
            'enabled' => true,
            'key_prefix' => 'proxy:probe-cache:',
            // Checker node identity for ProbeCacheKeyV3 (T31; §11.8 MVP:
            // a single checker node with local egress). No secrets here (R6.2).
            'checker_node_id' => 'node-1',
            'egress_identity' => 'local',
            // Probe-semantics version composed into probeSemanticsVersion by
            // the key factory; bump when observation semantics change without
            // a tool change (T30 deviation note).
            'probe_semantics_version' => 'v1',
            'default_ttl_seconds' => 1800,
            'negative_ttl_seconds' => 120,
            'ttl_by_kind' => [
                // SharedCacheValueKind value => seconds
                'http_measurement' => 1800,
                'timing_measurement' => 900,
                'exit_ip_observation' => 3600,
                'dns_observation' => 3600,
                'marker_result' => 1800,
                'anonymity_header_flags' => 3600,
                'negative_probe_result' => 120,
            ],
        ],
        // Dynamic pool materialization safety valve (T33; §11.25): a
        // candidate set above this cap aborts the run — no partial
        // materialization.
        'pools' => [
            'max_members' => 10000,
        ],
        // ProxyLease lifecycle (T34; §11.24): TTL default 300s (§10.12 п.6),
        // heartbeat renewal; expired leases are reclaimed by the reaper
        // (T35) in batches.
        'leases' => [
            'ttl_seconds' => 300,
            'reaper_batch_size' => 200,
        ],
        // ProxySelector (T35; §11.25): strategy ordering + freshness gate.
        // Stale candidates get a light lazy-check job (trigger
        // lazy_selection) and are skipped until the audit completes.
        'selection' => [
            'strategy' => 'round_robin', // round_robin|random|least_used|weighted
            'lazy_check_probe_profile' => 'light',
        ],
        // Task delivery to the checker worker (T25; plan §§11.9, 11.35 пп.5–6).
        'delivery' => [
            // Runtime sealing key for SealedCredentialPayload (§11.35 п.5,
            // R6.5): the application decrypts the DEK envelope in-process and
            // re-seals the credential with this separate runtime key; the
            // worker never receives KEK/DEK material (INV-004). Env is for
            // secrets only.
            'seal_key' => env('PROXY_AUDIT_SEAL_KEY'),
            // SECURITY WARNING: Same as encryption.fallback_to_app_key —
            // only safe in local/testing. Set PROXY_AUDIT_SEAL_KEY in production.
            'fallback_to_app_key' => true,
            // Sealed payload TTL = job TTL (plan §11.35 п.5).
            'sealed_ttl_seconds' => 3600,
            // Wall-clock deadline stamped into every AuditTaskV1.
            'task_deadline_seconds' => 900,
            // Per-probe output cap copied into ProbeExecutionSpecV1 (INV-019).
            'probe_max_output_bytes' => 1024 * 1024,
            // Per-attempt delivery retry budget (§11.27 scheduler budgets stay
            // in the policy snapshot; these are small per-delivery retries).
            // Budget exhaustion dead-letters the attempt.
            'retry' => [
                'max_attempts' => 3,
            ],
            // System dictionary versions. No persisted judge/TG-DC snapshot
            // store exists yet (the policy snapshot carries only probe/threshold/
            // quarantine content), so target_set versions resolve from these
            // defaults until such a store lands (documented T25 deviation).
            'judge_set_version' => 1,
            'tg_dc_set_version' => 1,
            // Redis Streams endpoints. The client is injected from host wiring
            // (INV-009: no Redis client types are constructed in src).
            //
            // IMPORTANT: This is an intentional isolation — the proxy module uses
            // its own Redis connection for performance and operational separation.
            // It does NOT go through Laravel's Redis abstraction (config/database.php)
            // because: (1) Redis Streams require a dedicated connection for latency,
            // (2) the checker worker runs in a separate container and needs direct
            // DSN access, (3) proxy traffic must not compete with app Redis traffic.
            'streams' => [
                'tasks' => 'proxy:audit:tasks',
                'results' => 'proxy:audit:results',
                'dsn' => env('PROXY_AUDIT_REDIS_DSN', 'tcp://127.0.0.1:6379'),
            ],
        ],
    ],

    // Reserved for probe defaults shared by the checker pipeline (stages 3–5).
    'probe_defaults' => [],

    // Checker pipeline settings (plan §11.17, §11.39 п.19; consumed by T22).
    'checker' => [
        // Default probe timeout per tier (ms). Override per-job via AuditPolicySnapshot.
        // Mirrors ToolTimeoutFactory constants; the factory itself stays pure.
        'timeouts' => [
            'aggressive' => 5000,
            'standard' => 15000,
            'generous' => 30000,
        ],
        // Judge selection: 'round_robin' | 'all' | 'random' (JudgeSelectionStrategy).
        'judge_selection' => 'round_robin',
        // Maximum probes per AuditTaskV1 execution (safety valve).
        'max_probes_per_task' => 50,
        // JudgeBudgetConfig defaults: per-judge sliding-window rate limit (INV-009).
        'judge_budget' => [
            'rate_limit_per_minute' => 60,
            'window_seconds' => 60,
        ],
    ],
];
