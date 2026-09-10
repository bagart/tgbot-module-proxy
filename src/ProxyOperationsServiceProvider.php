<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations;

use BAGArt\ASKClientRedis\Redis\ASKRedisClientFactory;
use BAGArt\ASKClientRedis\Redis\RedisDsn;
use BAGArt\ProxyOperations\Audit\AuditDeliveryQueue;
use BAGArt\ProxyOperations\Audit\AuditEventConsumer;
use BAGArt\ProxyOperations\Audit\AuditEventRecorder;
use BAGArt\ProxyOperations\Audit\AuditTaskFactory;
use BAGArt\ProxyOperations\Audit\CacheJobPlacementDedup;
use BAGArt\ProxyOperations\Audit\CachePolicy;
use BAGArt\ProxyOperations\Audit\CredentialSealer;
use BAGArt\ProxyOperations\Audit\DbAuditEventRecorder;
use BAGArt\ProxyOperations\Audit\DeliveryDispatcher;
use BAGArt\ProxyOperations\Audit\DeliveryRetryPolicy;
use BAGArt\ProxyOperations\Audit\DimensionalHealthEvaluator;
use BAGArt\ProxyOperations\Audit\EventOutboxDispatcher;
use BAGArt\ProxyOperations\Audit\EventOutboxTick;
use BAGArt\ProxyOperations\Audit\EventTypeRegistry;
use BAGArt\ProxyOperations\Audit\HealthEvaluator;
use BAGArt\ProxyOperations\Audit\JobPlacementDedup;
use BAGArt\ProxyOperations\Audit\JobStarter;
use BAGArt\ProxyOperations\Audit\LaravelCacheProbeCache;
use BAGArt\ProxyOperations\Audit\LaravelCacheProbeCacheMetrics;
use BAGArt\ProxyOperations\Audit\LaravelLeaseLockStore;
use BAGArt\ProxyOperations\Audit\LeaseLockStore;
use BAGArt\ProxyOperations\Audit\LeaseReaperCommand;
use BAGArt\ProxyOperations\Audit\LeaseService;
use BAGArt\ProxyOperations\Audit\PoolRepository;
use BAGArt\ProxyOperations\Audit\ProxySelector;
use BAGArt\ProxyOperations\Audit\PolicySnapshotBuilder;
use BAGArt\ProxyOperations\Audit\ProbeCache;
use BAGArt\ProxyOperations\Audit\ProbeCacheKeyFactory;
use BAGArt\ProxyOperations\Audit\ProbeCacheMetrics;
use BAGArt\ProxyOperations\Audit\RedisStreamsAuditDeliveryQueue;
use BAGArt\ProxyOperations\Checker\CacheAwareProbePlanner;
use BAGArt\ProxyOperations\Domain\Evidence\FreshnessAwareEligibilityPolicy;
use BAGArt\ProxyOperations\Domain\Evidence\VerifiedEligibilityPolicy;
use BAGArt\ProxyOperations\Domain\Lease\LeastUsedSelectionStrategy;
use BAGArt\ProxyOperations\Domain\Lease\RandomSelectionStrategy;
use BAGArt\ProxyOperations\Domain\Lease\RoundRobinSelectionStrategy;
use BAGArt\ProxyOperations\Domain\Lease\WeightedSelectionStrategy;
use BAGArt\ProxyOperations\Checker\ExecutionResultNormalizer;
use BAGArt\ProxyOperations\Checker\JudgeBudgetConfig;
use BAGArt\ProxyOperations\Checker\JudgeBudgetTracker;
use BAGArt\ProxyOperations\Checker\JudgeProvider;
use BAGArt\ProxyOperations\Checker\JudgeSelectionStrategy;
use BAGArt\ProxyOperations\Checker\JudgeSetProvider;
use BAGArt\ProxyOperations\Checker\ProbeExecutor;
use BAGArt\ProxyOperations\Checker\ProbeOutcomeClassifier;
use BAGArt\ProxyOperations\Checker\ToolTimeoutFactory;
use BAGArt\ProxyOperations\Domain\Failure\FailureTaxonomy;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Domain\Lifecycle\HysteresisPolicy;
use BAGArt\ProxyOperations\Domain\Parsing\ProxyListParser;
use BAGArt\ProxyOperations\Encryption\ConfigKekProvider;
use BAGArt\ProxyOperations\Encryption\CredentialEncryptor;
use BAGArt\ProxyOperations\Encryption\KekProvider;
use BAGArt\ProxyOperations\Parser\ImportProxiesService;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use BAGArt\ProxyOperations\Tool\ResourceGovernorSpec;
use BAGArt\ProxyOperations\Tool\ToolRegistry;
use BAGArt\ProxyOperations\Transport\Adapters\DirectAdapter;
use BAGArt\ProxyOperations\Transport\Adapters\HttpConnectAdapter;
use BAGArt\ProxyOperations\Transport\Adapters\Socks4Adapter;
use BAGArt\ProxyOperations\Transport\Adapters\Socks5Adapter;
use BAGArt\ProxyOperations\Transport\Adapters\Socks5UdpAdapter;
use BAGArt\ProxyOperations\Transport\Adapters\UdpAssociateProbeContract;
use BAGArt\ProxyOperations\Transport\CapabilityProbeRunner;
use BAGArt\ProxyOperations\Transport\DnsResolverFactory;
use BAGArt\ProxyOperations\Transport\ResourceGovernor;
use BAGArt\ProxyOperations\Transport\RunCapabilityProbesCommand;
use BAGArt\ProxyOperations\Transport\TransportAdapterResolver;
use BAGArt\ProxyOperations\Transport\TransportToolManifestProvider;
use BAGArt\ProxyOperations\Transport\WorkerControlPlaneHandler;
use BAGArt\ProxyOperations\Transport\WorkerExecutionPlaneHandler;
use Illuminate\Support\ServiceProvider;

final class ProxyOperationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/proxy-operations.php', 'proxy-operations');

        $this->app->scoped(TenantContext::class);
        $this->app->singleton(KekProvider::class, ConfigKekProvider::class);
        $this->app->singleton(CredentialEncryptor::class);
        $this->app->singleton(ProxyListParser::class);
        $this->app->singleton(ImportProxiesService::class);

        $this->registerTransport();

        $this->registerSharedCache();

        $this->registerChecker();

        $this->registerAudit();

        $this->registerAuditDelivery();

        $this->registerLeases();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * Stage 5 audit placement bindings (T24; plan §§11.18, 11.27).
     */
    private function registerAudit(): void
    {
        $this->app->singleton(JobPlacementDedup::class, CacheJobPlacementDedup::class);

        $this->app->singleton(PolicySnapshotBuilder::class);

        $this->app->singleton(JobStarter::class, static function ($app) {
            return new JobStarter(
                snapshots: $app->make(PolicySnapshotBuilder::class),
                placement: $app->make(JobPlacementDedup::class),
                tenant: $app->make(TenantContext::class),
                placementTtlSeconds: (int) config('proxy-operations.audit.placement_ttl_seconds', 300),
            );
        });

        // T27 (§§11.6, 11.35 пп.9–10): the production evaluator behind the
        // T26 ingestion contract, configured from audit.health.
        $this->app->singleton(HealthEvaluator::class, static function () {
            $health = (array) config('proxy-operations.audit.health', []);
            $hysteresis = (array) ($health['hysteresis'] ?? []);

            return new DimensionalHealthEvaluator(
                hysteresis: new HysteresisPolicy(
                    consecutiveFailuresToDegrade: (int) ($hysteresis['consecutive_failures_to_degrade'] ?? 2),
                    consecutiveFailuresToFailing: (int) ($hysteresis['consecutive_failures_to_failing'] ?? 5),
                    consecutiveFailuresToDeclareDead: (int) ($hysteresis['consecutive_failures_to_declare_dead'] ?? 10),
                    consecutiveSuccessesToLeaveFailing: (int) ($hysteresis['consecutive_successes_to_leave_failing'] ?? 2),
                    consecutiveSuccessesToLeaveDegraded: (int) ($hysteresis['consecutive_successes_to_leave_degraded'] ?? 3),
                    minSecondsBetweenTransitions: (int) ($hysteresis['min_seconds_between_transitions'] ?? 0),
                ),
                telegramFreshnessSeconds: (int) ($health['telegram_freshness_seconds'] ?? 21600),
                healthFormulaVersion: (string) ($health['health_formula_version'] ?? 'dimensional-v1'),
            );
        });

        // T28 (§§11.20, 11.35 пп.18–19): event catalog, production recorder
        // (the outbox append inside the ingestion transaction) and the
        // post-commit dispatcher.
        $this->app->singleton(EventTypeRegistry::class, static function () {
            return EventTypeRegistry::fromConfig(
                (array) config('proxy-operations.audit.events', []),
            );
        });

        $this->app->singleton(AuditEventRecorder::class, DbAuditEventRecorder::class);

        // Consumers register by tagging. None exist yet in src — projections
        // and notifications are added when their tasks land.
        $this->app->tag([], 'proxy-operations.audit-event-consumers');

        $this->app->singleton(EventOutboxDispatcher::class, static function ($app) {
            return new EventOutboxDispatcher(
                consumers: iterator_to_array($app->tagged('proxy-operations.audit-event-consumers'), false),
                batchSize: (int) config('proxy-operations.audit.events.outbox_batch_size', 100),
            );
        });

        $this->app->singleton(EventOutboxTick::class);
    }

    /**
     * Stage 5 task delivery bindings (T25; plan §§11.9, 11.35 п.5).
     */
    private function registerAuditDelivery(): void
    {
        $this->app->singleton(CredentialSealer::class, static function () {
            return new CredentialSealer(
                encryptor: app(CredentialEncryptor::class),
                runtimeKey: CredentialSealer::runtimeKeyFromConfig(),
                ttlSeconds: (int) config('proxy-operations.audit.delivery.sealed_ttl_seconds', 3600),
            );
        });

        $this->app->singleton(AuditTaskFactory::class, static function () {
            return new AuditTaskFactory(
                sealer: app(CredentialSealer::class),
                maxAttempts: (int) config('proxy-operations.audit.delivery.retry.max_attempts', 3),
                deadlineSeconds: (int) config('proxy-operations.audit.delivery.task_deadline_seconds', 900),
                probeMaxOutputBytes: (int) config('proxy-operations.audit.delivery.probe_max_output_bytes', 1024 * 1024),
            );
        });

        $this->app->singleton(DeliveryRetryPolicy::class, static function () {
            return new DeliveryRetryPolicy(
                maxAttempts: (int) config('proxy-operations.audit.delivery.retry.max_attempts', 3),
            );
        });

        // Lazy-connecting client adapter injected as a contract (INV-009:
        // no Redis client is constructed or imported inside Audit classes
        // beyond this host-facing wiring).
        $this->app->singleton(RedisStreamsAuditDeliveryQueue::class, static function () {
            $streams = (array) config('proxy-operations.audit.delivery.streams', []);

            return new RedisStreamsAuditDeliveryQueue(
                redis: (new ASKRedisClientFactory)->create(
                    RedisDsn::parse((string) ($streams['dsn'] ?? 'tcp://127.0.0.1:6379')),
                ),
                tasksStream: (string) ($streams['tasks'] ?? 'proxy:audit:tasks'),
                resultsStream: (string) ($streams['results'] ?? 'proxy:audit:results'),
            );
        });

        // Production queue; tests rebind the in-memory fake.
        $this->app->singleton(AuditDeliveryQueue::class, RedisStreamsAuditDeliveryQueue::class);

        $this->app->singleton(DeliveryDispatcher::class);
    }

    private function registerTransport(): void
    {
        $this->app->singleton(TransportAdapterResolver::class, static function () {
            $resolver = new TransportAdapterResolver;
            $httpAdapter = new HttpConnectAdapter;
            $socks4Adapter = new Socks4Adapter;
            $socks5Adapter = new Socks5Adapter;
            $directAdapter = new DirectAdapter;

            $resolver->register(ProxyProtocol::Http, $httpAdapter);
            $resolver->register(ProxyProtocol::Https, $httpAdapter);
            $resolver->register(ProxyProtocol::Socks4, $socks4Adapter);
            $resolver->register(ProxyProtocol::Socks4a, $socks4Adapter);
            $resolver->register(ProxyProtocol::Socks5, $socks5Adapter);
            $resolver->register(ProxyProtocol::Socks5h, $socks5Adapter);

            return $resolver;
        });

        $this->app->singleton(DnsResolverFactory::class);

        $this->app->singleton(UdpAssociateProbeContract::class, static function () {
            return new Socks5UdpAdapter;
        });

        $this->app->singleton(ResourceGovernorSpec::class, static function () {
            $config = config('proxy-operations.resource_governor', []);

            return new ResourceGovernorSpec(
                maxConcurrentProbes: (int) ($config['max_concurrent_probes'] ?? 50),
                maxProcesses: (int) ($config['max_processes'] ?? 100),
                maxMemoryBytes: (int) ($config['max_memory_bytes'] ?? 512 * 1024 * 1024),
                maxCpuPercent: (int) ($config['max_cpu_percent'] ?? 80),
                maxExecutionTimeSeconds: (int) ($config['max_execution_time_seconds'] ?? 30),
                maxOutputBytes: (int) ($config['max_output_bytes'] ?? 1024 * 1024),
                maxStdinBytes: (int) ($config['max_stdin_bytes'] ?? 1024 * 1024),
                maxFileDescriptors: (int) ($config['max_file_descriptors'] ?? 256),
            );
        });

        $this->app->singleton(ResourceGovernor::class, static function ($app) {
            return new ResourceGovernor($app->make(ResourceGovernorSpec::class));
        });

        $this->app->singleton(CapabilityProbeRunner::class);

        $this->app->singleton(TransportToolManifestProvider::class);

        $this->app->singleton(ToolRegistry::class, static function ($app) {
            $provider = $app->make(TransportToolManifestProvider::class);
            $manifests = [];
            foreach ($provider->manifests() as $manifest) {
                $manifests[$manifest->name->value] = $manifest;
            }

            return new ToolRegistry($manifests);
        });

        $this->app->singleton(WorkerControlPlaneHandler::class);

        $this->app->singleton(WorkerExecutionPlaneHandler::class);
    }

    /**
     * Stage 6 shared raw-probe cache bindings (T29/T31; plan §§11.7, 11.14).
     */
    private function registerSharedCache(): void
    {
        $this->app->singleton(CachePolicy::class, static function () {
            return CachePolicy::fromConfig(
                (array) config('proxy-operations.audit.cache', []),
            );
        });

        // Lazy store access (INV-009): the Laravel cache is only touched
        // from within ProbeCache method calls, never at resolve time.
        $this->app->singleton(ProbeCache::class, LaravelCacheProbeCache::class);

        $this->app->singleton(ProbeCacheMetrics::class, LaravelCacheProbeCacheMetrics::class);

        // Node identity for ProbeCacheKeyV3 (§11.8 MVP: single checker node,
        // local egress). Dictionary versions mirror the T25 delivery defaults.
        $this->app->singleton(ProbeCacheKeyFactory::class, static function () {
            return new ProbeCacheKeyFactory([
                'checker_node_id' => (string) config('proxy-operations.audit.cache.checker_node_id', 'node-1'),
                'egress_identity' => (string) config('proxy-operations.audit.cache.egress_identity', 'local'),
                'judge_set_version' => (int) config('proxy-operations.audit.delivery.judge_set_version', 1),
                'tg_dc_set_version' => (int) config('proxy-operations.audit.delivery.tg_dc_set_version', 1),
                'tool_semantics_version' => (string) config('proxy-operations.audit.cache.tool_semantics_version', 'builtin-v1'),
            ]);
        });
    }

    /**
     * Stage 7 lease + selection bindings (T34/T35; plan §§11.24–11.25).
     */
    private function registerLeases(): void
    {
        $this->app->singleton(LeaseLockStore::class, LaravelLeaseLockStore::class);

        $this->app->singleton(LeaseService::class, static function () {
            return new LeaseService(
                locks: app(LeaseLockStore::class),
                events: app(AuditEventRecorder::class),
                ttlSeconds: (int) config('proxy-operations.audit.leases.ttl_seconds', 300),
                reaperBatchSize: (int) config('proxy-operations.audit.leases.reaper_batch_size', 200),
            );
        });

        $this->app->singleton(PoolRepository::class);

        $this->app->singleton(VerifiedEligibilityPolicy::class, static function () {
            return new FreshnessAwareEligibilityPolicy(
                telegramFreshnessSeconds: (int) config('proxy-operations.audit.health.telegram_freshness_seconds', 21600),
            );
        });

        $this->app->singleton(ProxySelector::class, static function ($app) {
            $strategies = [
                'round_robin' => RoundRobinSelectionStrategy::class,
                'random' => RandomSelectionStrategy::class,
                'least_used' => LeastUsedSelectionStrategy::class,
                'weighted' => WeightedSelectionStrategy::class,
            ];

            $strategy = $strategies[(string) config('proxy-operations.audit.selection.strategy', 'round_robin')]
                ?? $strategies['round_robin'];

            return new ProxySelector(
                strategy: $app->make($strategy),
                leases: $app->make(LeaseService::class),
                eligibility: $app->make(VerifiedEligibilityPolicy::class),
                jobStarter: $app->make(JobStarter::class),
                lazyCheckProbeProfile: (string) config('proxy-operations.audit.selection.lazy_check_probe_profile', 'light'),
            );
        });
    }

    /**
     * Stage 4 checker bindings (T22; plan §§11.14, 11.30, 11.39 п.19).
     */
    private function registerChecker(): void
    {
        $this->app->singleton(JudgeBudgetConfig::class, static function () {
            $config = config('proxy-operations.checker.judge_budget', []);

            return new JudgeBudgetConfig(
                rateLimitPerMinute: (int) ($config['rate_limit_per_minute'] ?? 60),
                windowSeconds: (int) ($config['window_seconds'] ?? 60),
            );
        });

        // Transient by design (T22): a fresh rate-limit window per execution.
        $this->app->bind(JudgeBudgetTracker::class);

        $this->app->singleton(ToolTimeoutFactory::class);

        $this->app->singleton(FailureTaxonomy::class);

        $this->app->singleton(ProbeOutcomeClassifier::class);

        $this->app->singleton(ExecutionResultNormalizer::class);

        $this->app->singleton(JudgeProvider::class, static function ($app) {
            return new JudgeSetProvider(
                $app->make(JudgeBudgetTracker::class),
                JudgeSelectionStrategy::from(
                    (string) config('proxy-operations.checker.judge_selection', 'round_robin'),
                ),
            );
        });

        // No concrete ProbeTool implementations exist yet (Stage 5); the
        // executor is wired with an empty tool map so the full pipeline stays
        // resolvable. Tools are added to this map when they land.
        $this->app->singleton(ProbeExecutor::class, static function ($app) {
            return new ProbeExecutor(
                tools: [],
                judgeProvider: $app->make(JudgeProvider::class),
                toolRegistry: $app->make(ToolRegistry::class),
                governor: $app->make(ResourceGovernor::class),
                timeoutFactory: $app->make(ToolTimeoutFactory::class),
            );
        });

        // T31 (§11.14: shared cache ON at stage 6): the checker execution
        // path resolves the planner wrapper around the executor. With the
        // policy disabled the planner delegates straight through, so the
        // plain ProbeExecutor singleton above stays the single cache-off
        // posture for tests that rebind it.
        $this->app->singleton(CacheAwareProbePlanner::class, static function ($app) {
            return new CacheAwareProbePlanner(
                executor: $app->make(ProbeExecutor::class),
                cache: $app->make(ProbeCache::class),
                policy: $app->make(CachePolicy::class),
                keyFactory: $app->make(ProbeCacheKeyFactory::class),
                metrics: $app->make(ProbeCacheMetrics::class),
                semanticsBase: (string) config('proxy-operations.audit.cache.probe_semantics_version', 'v1'),
            );
        });
    }
}
