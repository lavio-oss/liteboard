<?php

namespace Modules\Core\Resources\Tenant;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;
use Modules\Core\Models\Tenant\Tenant;

/**
 * @mixin Tenant
 */
final class TenantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pivot = $this->resource->getRelationValue('pivot');

        $role = $pivot instanceof Pivot ? $pivot->getAttribute('role') : null;

        if ($role !== null && ! is_string($role)) {
            throw new LogicException('The tenant membership pivot role must be a string when present.');
        }

        return [
            'id' => (string) $this->getKey(),
            'name' => (string) $this->name,
            'code' => (string) $this->code,
            'status' => (string) $this->status,
            'role' => $role,
        ];
    }
}
