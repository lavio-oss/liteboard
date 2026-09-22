<?php

namespace Modules\Core\Resources\Tenant;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;
use Modules\Core\Models\User\User;

/** @mixin User */
final class TenantUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $pivot = $user->getRelationValue('pivot');

        if (! $pivot instanceof Pivot || ! is_string($pivot->getAttribute('role'))) {
            throw new LogicException('The tenant user pivot must include a string role.');
        }

        return [
            'id' => (int) $user->getKey(),
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'role' => $pivot->getAttribute('role'),
            'system_role' => $user->getAttribute('system_role'),
        ];
    }
}
