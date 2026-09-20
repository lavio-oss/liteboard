<?php

namespace Modules\Core\Resources\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @mixin PersonalAccessToken
 */
final class PersonalAccessTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'abilities' => is_array($this->abilities) ? array_values($this->abilities) : [],
            'created_at' => $this->created_at?->toISOString(),
            'last_used_at' => $this->last_used_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
        ];
    }
}
