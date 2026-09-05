<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Checker;

use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Domain\Snapshot\JudgeDescriptor;
use BAGArt\ProxyOperations\Domain\Snapshot\JudgeSetSnapshot;
use BAGArt\ProxyOperations\Domain\Snapshot\JudgeTrustTier;

/**
 * Resolves judges from a JudgeSetSnapshot using the configured selection strategy
 * and rate-limit budget tracker (plan §§11.8, 11.17, 11.35 п.8, 11.39 п.19).
 *
 * Responsibilities:
 * - Filter judges by capability match for the requested ProbeType.
 * - Apply selection strategy: All, RoundRobin, or Random.
 * - Respect rateLimitPerMinute via JudgeBudgetTracker.
 * - Prefer higher-trust judges when count < available.
 */
final class JudgeSetProvider implements JudgeProvider
{
    /** @var array<string, int>  monotonic counter per (snapshotId, probeType) for round-robin */
    private array $roundRobinCounters;

    public function __construct(
        private readonly JudgeBudgetTracker $budgetTracker,
        private readonly JudgeSelectionStrategy $strategy = JudgeSelectionStrategy::RoundRobin,
        array $roundRobinCounters = [],
    ) {
        $this->roundRobinCounters = $roundRobinCounters;
    }

    /**
     * @return list<JudgeDescriptor>
     */
    public function select(
        JudgeSetSnapshot $snapshot,
        ProbeType $probeType,
        int $count,
    ): array {
        $eligible = $this->filterByCapability($snapshot->judges, $probeType);

        $eligible = array_filter(
            $eligible,
            fn (JudgeDescriptor $judge): bool => $this->budgetTracker->allow($judge->id),
        );
        $eligible = array_values($eligible);

        if ($eligible === []) {
            return [];
        }

        $eligible = $this->sortByTrustTier($eligible);

        $selected = match ($this->strategy) {
            JudgeSelectionStrategy::All => $eligible,
            JudgeSelectionStrategy::RoundRobin => $this->selectRoundRobin($eligible, $snapshot->setId, $probeType, $count),
            JudgeSelectionStrategy::Random => $this->selectRandom($eligible, $count),
        };

        return array_slice($selected, 0, $count);
    }

    /**
     * @param  list<JudgeDescriptor>  $judges
     * @return list<JudgeDescriptor>
     */
    private function filterByCapability(array $judges, ProbeType $probeType): array
    {
        return array_values(array_filter(
            $judges,
            static fn (JudgeDescriptor $judge): bool => in_array($probeType->value, $judge->capabilities, strict: true),
        ));
    }

    /**
     * @param  list<JudgeDescriptor>  $judges
     * @return list<JudgeDescriptor>
     */
    private function sortByTrustTier(array $judges): array
    {
        usort($judges, static function (JudgeDescriptor $a, JudgeDescriptor $b): int {
            $tierOrder = [
                JudgeTrustTier::SelfHosted->value => 0,
                JudgeTrustTier::PublicHttps->value => 1,
            ];

            return ($tierOrder[$a->trustTier->value] ?? 2) <=> ($tierOrder[$b->trustTier->value] ?? 2);
        });

        return $judges;
    }

    /**
     * @param  list<JudgeDescriptor>  $eligible
     * @return list<JudgeDescriptor>
     */
    private function selectRoundRobin(array $eligible, string $setId, ProbeType $probeType, int $count): array
    {
        $key = $setId.':'.$probeType->value;
        $index = $this->roundRobinCounters[$key] ?? 0;
        $total = count($eligible);

        if ($total === 0) {
            return [];
        }

        $selected = [];
        for ($i = 0; $i < min($count, $total); $i++) {
            $selected[] = $eligible[($index + $i) % $total];
        }

        $this->roundRobinCounters[$key] = ($index + 1) % $total;

        return $selected;
    }

    /**
     * @param  list<JudgeDescriptor>  $eligible
     * @return list<JudgeDescriptor>
     */
    private function selectRandom(array $eligible, int $count): array
    {
        $shuffled = $eligible;
        shuffle($shuffled);

        return array_slice($shuffled, 0, $count);
    }
}
