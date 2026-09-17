<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Incident;

use BAGArt\ProxyOperations\Models\Incident;
use BAGArt\ProxyOperations\Models\IncidentAction;
use BAGArt\ProxyOperations\Models\IncidentSeverity;
use BAGArt\ProxyOperations\Models\IncidentStatus;
use Illuminate\Support\Facades\Log;

/**
 * Incident escalation engine (plan §§10.12 п.12, 11.10).
 * Manages status transitions and escalation rules.
 */
final class IncidentEscalator
{
    /**
     * Escalate an incident based on age and severity.
     */
    public function escalate(Incident $incident): Incident
    {
        if ($incident->status === IncidentStatus::Resolved) {
            return $incident;
        }

        $ageMinutes = $incident->created_at->diffInMinutes(now());

        $newSeverity = match (true) {
            $ageMinutes > 60 && $incident->severity === IncidentSeverity::Warning => IncidentSeverity::Critical,
            $ageMinutes > 30 && $incident->severity === IncidentSeverity::Info => IncidentSeverity::Warning,
            $ageMinutes > 120 && $incident->severity === IncidentSeverity::Critical => IncidentSeverity::Emergency,
            default => $incident->severity,
        };

        if ($newSeverity !== $incident->severity) {
            $incident->update(['severity' => $newSeverity]);

            IncidentAction::create([
                'incident_id' => $incident->id,
                'action_type' => 'escalate',
                'description' => "Severity escalated from {$incident->severity->value} to {$newSeverity->value}.",
                'performed_by' => 'system',
            ]);

            Log::warning('proxy.incident.escalated', [
                'incident_id' => $incident->id,
                'old_severity' => $incident->severity->value,
                'new_severity' => $newSeverity->value,
            ]);
        }

        return $incident->fresh();
    }

    /**
     * Transition incident status.
     */
    public function transition(Incident $incident, IncidentStatus $newStatus, string $performedBy = 'system'): Incident
    {
        $oldStatus = $incident->status;

        if ($oldStatus === $newStatus) {
            return $incident;
        }

        $incident->update(['status' => $newStatus]);

        if ($newStatus === IncidentStatus::Resolved) {
            $incident->update(['resolved_at' => now()]);
        }

        IncidentAction::create([
            'incident_id' => $incident->id,
            'action_type' => 'transition',
            'description' => "Status changed from {$oldStatus->value} to {$newStatus->value}.",
            'performed_by' => $performedBy,
        ]);

        Log::info('proxy.incident.transitioned', [
            'incident_id' => $incident->id,
            'old_status' => $oldStatus->value,
            'new_status' => $newStatus->value,
        ]);

        return $incident->fresh();
    }

    /**
     * Add a note to an incident.
     */
    public function addNote(Incident $incident, string $note, string $performedBy = 'system'): IncidentAction
    {
        return IncidentAction::create([
            'incident_id' => $incident->id,
            'action_type' => 'note',
            'description' => $note,
            'performed_by' => $performedBy,
        ]);
    }
}
