<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for ProxyPool (plan §11.12).
 */
class PoolResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'enabled' => $this->enabled,
            'description' => $this->description,
            'member_count' => $this->when(isset($this->members_count)),
            'last_materialized_at' => $this->last_materialized_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
