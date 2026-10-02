<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Incident;

use BAGArt\ProxyOperations\Models\Incident;
use BAGArt\ProxyOperations\Models\IncidentSeverity;
use BAGArt\ProxyOperations\Models\IncidentStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Incident detection engine (plan §§10.12 п.12, 11.10).
 * Monitors proxy health and creates incidents on threshold breaches.
 */
final class IncidentDetector
{
    private const int CRITICAL_FAILURE_THRESHOLD = 10;
    private const float HEALTH_DROP_PERCENTAGE = 30.0;
    private const int DETECTION_WINDOW_MINUTES = 15;

    /**
     * Detect health threshold breaches and create incidents.
     *
     * @return list<Incident>
     */
    public function detect(): array
    {
        $incidents = [];

        $incidents = array_merge($incidents, $this->detectMassFailures());
        $incidents = array_merge($incidents, $this->detectHealthDrops());
        $incidents = array_merge($incidents, $this->detectLeaseExhaustion());

        return $incidents;
    }

    /**
     * Detect mass endpoint failures (plan §10.12 п.12 item 7).
     */
    private function detectMassFailures(): array
    {
        $window = now()->subMinutes(self::DETECTION_WINDOW_MINUTES);

        $failureCounts = DB::table('proxy_audit_entries')
            ->where('observed_at', '>=', $window)
            ->where('status', 'failed')
            ->select('tenant_id', DB::raw('COUNT(*) as failure_count'))
            ->groupBy('tenant_id')
            ->having('failure_count', '>=', self::CRITICAL_FAILURE_THRESHOLD)
            ->get();

        $incidents = [];

        foreach ($failureCounts as $row) {
            $existing = Incident::where('tenant_id', $row->tenant_id)
                ->where('title', 'Mass endpoint failures')
                ->whereNot('status', IncidentStatus::Resolved->value)
                ->exists();

            if (! $existing) {
                $incident = Incident::create([
                    'tenant_id' => $row->tenant_id,
                    'title' => 'Mass endpoint failures',
                    'description' => "{$row->failure_count} endpoints failed in the last " . self::DETECTION_WINDOW_MINUTES . ' minutes.',
                    'severity' => IncidentSeverity::Critical,
                    'status' => IncidentStatus::Open,
                    'source' => 'health_monitor',
                ]);

                $incidents[] = $incident;

                Log::warning('proxy.incident.detected', [
                    'incident_id' => $incident->id,
                    'type' => 'mass_failures',
                    'tenant_id' => $row->tenant_id,
                    'count' => $row->failure_count,
                ]);
            }
        }

        return $incidents;
    }

    /**
     * Detect significant health score drops (plan §10.12 п.12 item 8).
     */
    private function detectHealthDrops(): array
    {
        $window = now()->subMinutes(self::DETECTION_WINDOW_MINUTES);

        $endpoints = DB::table('proxy_health_snapshots')
            ->where('created_at', '>=', $window)
            ->select('endpoint_id', 'tenant_id')
            ->selectRaw('MAX(health_score) as max_score, MIN(health_score) as min_score')
            ->groupBy('endpoint_id', 'tenant_id')
            ->havingRaw('MAX(health_score) - MIN(health_score) >= ?', [self::HEALTH_DROP_PERCENTAGE])
            ->get();

        $incidents = [];

        foreach ($endpoints as $row) {
            $existing = Incident::where('tenant_id', $row->tenant_id)
                ->where('title', 'Health score drop')
                ->whereNot('status', IncidentStatus::Resolved->value)
                ->exists();

            if (! $existing) {
                $incident = Incident::create([
                    'tenant_id' => $row->tenant_id,
                    'title' => 'Health score drop',
                    'description' => "Endpoint #{$row->endpoint_id} dropped from {$row->max_score} to {$row->min_score}.",
                    'severity' => IncidentSeverity::Warning,
                    'status' => IncidentStatus::Open,
                    'source' => 'health_monitor',
                    'affected_endpoints' => [$row->endpoint_id],
                ]);

                $incidents[] = $incident;

                Log::warning('proxy.incident.detected', [
                    'incident_id' => $incident->id,
                    'type' => 'health_drop',
                    'tenant_id' => $row->tenant_id,
                    'endpoint_id' => $row->endpoint_id,
                ]);
            }
        }

        return $incidents;
    }

    /**
     * Detect lease exhaustion (plan §10.12 п.12 item 9).
     */
    private function detectLeaseExhaustion(): array
    {
        $window = now()->subMinutes(self::DETECTION_WINDOW_MINUTES);

        $leases = DB::table('proxy_access_grants')
            ->where('acquired_at', '>=', $window)
            ->select('tenant_id', DB::raw('COUNT(*) as lease_count'))
            ->groupBy('tenant_id')
            ->having('lease_count', '>=', 100)
            ->get();

        $incidents = [];

        foreach ($leases as $row) {
            $existing = Incident::where('tenant_id', $row->tenant_id)
                ->where('title', 'Lease exhaustion')
                ->whereNot('status', IncidentStatus::Resolved->value)
                ->exists();

            if (! $existing) {
                $incident = Incident::create([
                    'tenant_id' => $row->tenant_id,
                    'title' => 'Lease exhaustion',
                    'description' => "{$row->lease_count} leases acquired in the last " . self::DETECTION_WINDOW_MINUTES . ' minutes.',
                    'severity' => IncidentSeverity::Warning,
                    'status' => IncidentStatus::Open,
                    'source' => 'health_monitor',
                ]);

                $incidents[] = $incident;

                Log::warning('proxy.incident.detected', [
                    'incident_id' => $incident->id,
                    'type' => 'lease_exhaustion',
                    'tenant_id' => $row->tenant_id,
                    'count' => $row->lease_count,
                ]);
            }
        }

        return $incidents;
    }

    /**
     * Create an incident manually.
     */
    public function create(
        string $tenantId,
        string $title,
        string $description,
        IncidentSeverity $severity = IncidentSeverity::Info,
        string $source = 'manual',
        ?array $affectedEndpoints = null,
    ): Incident {
        $incident = Incident::create([
            'tenant_id' => $tenantId,
            'title' => $title,
            'description' => $description,
            'severity' => $severity,
            'status' => IncidentStatus::Open,
            'source' => $source,
            'affected_endpoints' => $affectedEndpoints,
        ]);

        Log::info('proxy.incident.created', [
            'incident_id' => $incident->id,
            'tenant_id' => $tenantId,
            'severity' => $severity->value,
        ]);

        return $incident;
    }
}
