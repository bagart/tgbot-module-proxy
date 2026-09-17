<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for AuditJob status (plan §11.12).
 */
class AuditJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'trigger' => $this->trigger,
            'progress' => $this->progress,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
