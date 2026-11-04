<?php

namespace Modules\Core\Resources\Role;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Core\Enums\Role\Permission;
use Modules\Core\Models\Role\Role;

/** @mixin Role */
final class RoleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Role $role */
        $role = $this->resource;

        return [
            'id' => (string) $role->getKey(),
            'scope' => $role->scope->value,
            'key' => $role->key,
            'name' => $role->name,
            'is_system' => $role->is_system,
            'is_owner' => $role->isOwner(),
            'created_by' => $role->creator === null ? null : [
                'id' => (string) $role->creator->getKey(),
                'name' => (string) $role->creator->name,
            ],
            'created_at' => $role->created_at?->toISOString(),
            'permissions' => array_map(
                static fn (Permission $permission): string => $permission->value,
                $role->grantedPermissions(),
            ),
            'locked_permissions' => array_map(
                static fn (Permission $permission): string => $permission->value,
                $role->defaultRole()?->lockedPermissions() ?? [],
            ),
        ];
    }
}
