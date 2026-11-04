<?php

namespace Modules\Core\Resources\Tenant;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;
use Modules\Core\Enums\Role\Permission;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Resources\Role\RoleResource;
use Modules\Core\Services\Role\PermissionResolver;

/**
 * The tenant as seen by the requesting member: their tenant role and its effective permissions.
 *
 * @mixin Tenant
 */
final class TenantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Tenant $tenant */
        $tenant = $this->resource;
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('A tenant is only serialized for an authenticated member.');
        }

        $resolver = app(PermissionResolver::class);
        $role = $resolver->tenantRole($user, (string) $tenant->getKey());

        if ($role === null) {
            throw new LogicException('A tenant is only serialized for one of its members.');
        }

        $createdAt = $tenant->getAttribute('created_at');
        $updatedAt = $tenant->getAttribute('updated_at');

        return [
            'id' => (string) $tenant->getKey(),
            'name' => (string) $tenant->name,
            'code' => (string) $tenant->code,
            'status' => (string) $tenant->status,
            'role' => RoleResource::make($role->loadMissing('creator')),
            'permissions' => array_map(
                static fn (Permission $permission): string => $permission->value,
                $resolver->permissions($user, $tenant),
            ),
            'created_at' => $createdAt instanceof CarbonInterface ? $createdAt->toISOString() : null,
            'updated_at' => $updatedAt instanceof CarbonInterface ? $updatedAt->toISOString() : null,
        ];
    }
}
