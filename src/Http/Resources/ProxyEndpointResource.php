<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource for ProxyEndpoint — versioned additive-only contract (plan §11.12).
 */
class ProxyEndpointResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'protocol' => $this->protocol,
            'host' => $this->host,
            'port' => $this->port,
            'comment' => $this->comment,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
