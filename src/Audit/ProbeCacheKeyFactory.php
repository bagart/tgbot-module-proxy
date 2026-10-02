<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Domain\Cache\ProbeCacheKeyV3;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Wire\AuditTaskV1;

/**
 * Builds ProbeCacheKeyV3 identities on the worker (T30; plan §11.7): the
 * worker has no Postgres access, so every identity input comes from the
 * AuditTaskV1 itself or from the checker node's own config (§11.8 MVP:
 * a single node with local egress).
 *
 * Justified deviation (pre-declared in the T30 task file): ProbeCacheKeyV3
 * has no explicit probeType field — the V3 schema is frozen, so the factory
 * composes
 *
 *     probeSemanticsVersion = "<probeType->value>@<semanticsBase>"
 *
 * into the existing string field. The key stays deterministic and
 * tenant-free; different probe types can never collide under one profile
 * version.
 *
 * Secrets never participate: the credential enters only through the
 * CredentialFingerprint HMAC carried by the task (R6.2) — the worker never
 * sees the credential itself.
 */
final readonly class ProbeCacheKeyFactory
{
    /** Default node identity per plan §11.8 (single-node MVP). */
    private const string DEFAULT_CHECKER_NODE_ID = 'node-1';

    private const string DEFAULT_EGRESS_IDENTITY = 'local';

    private const string DEFAULT_TOOL_SEMANTICS_VERSION = 'builtin-v1';

    /**
     * @param  array<string,mixed>  $nodeIdentity  Checker node config: `checker_node_id`,
     *                                              `egress_identity`, `judge_set_version`,
     *                                              `tg_dc_set_version`, `tool_semantics_version`.
     *                                              Dictionary versions mirror the T25 sources
     *                                              (proxy-operations.audit.delivery.*); the planner
     *                                              passes them in because the worker keeps no
     *                                              snapshot store of its own.
     */
    public function __construct(private readonly array $nodeIdentity)
    {
    }

    /**
     * @param  string  $semanticsBase  Probe-semantics version of the planner build (bumped when
     *                                 observation semantics change without a tool change).
     */
    public function build(AuditTaskV1 $task, ProbeType $probeType, string $semanticsBase): ProbeCacheKeyV3
    {
        return new ProbeCacheKeyV3(
            // Canonical network identity of the probed endpoint, taken verbatim
            // from the task's endpoint snapshot (already canonical, §11.2).
            endpointIdentity: $task->accessRef->endpoint,
            // Already an HMAC fingerprint computed scheduler-side (R6.5).
            credentialFingerprint: $task->accessRef->credential,
            checkerNodeId: (string) ($this->nodeIdentity['checker_node_id'] ?? self::DEFAULT_CHECKER_NODE_ID),
            egressIdentity: (string) ($this->nodeIdentity['egress_identity'] ?? self::DEFAULT_EGRESS_IDENTITY),
            judgeSetVersion: (int) ($this->nodeIdentity['judge_set_version'] ?? 0),
            telegramDcSetVersion: (int) ($this->nodeIdentity['tg_dc_set_version'] ?? 0),
            // The task's frozen policy snapshot version is the worker's only
            // profile-version source (same value ObservationWriter persists).
            probeProfileVersion: $task->policySnapshotVersion,
            probeSemanticsVersion: $probeType->value.'@'.$semanticsBase,
            toolSemanticsVersion: (string) ($this->nodeIdentity['tool_semantics_version'] ?? self::DEFAULT_TOOL_SEMANTICS_VERSION),
        );
    }
}
