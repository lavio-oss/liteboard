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

        if (! $pivot instanceof Pivot || ! is_string($pivot->getAttribute('role'))) {
            throw new LogicException('The tenant membership pivot must include a string role.');
        }

        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'code' => (string) $this->code,
            'status' => (string) $this->status,
            'role' => $pivot->getAttribute('role'),
        ];
    }
}
