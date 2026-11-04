<?php

namespace Modules\Core\Policies\Role;

use Modules\Core\Enums\Role\Permission;
use Modules\Core\Models\Role\Role;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Services\Role\PermissionResolver;

final class RolePolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user, Tenant $tenant): bool
    {
        return $tenant->hasMember($user);
    }

    public function create(User $user, Tenant $tenant): bool
    {
        return $this->permissions->allows($user, Permission::RoleManage, $tenant);
    }

    public function update(User $user, Role $role): bool
    {
        return ! $role->isOwner()
            && $this->permissions->allows($user, Permission::RoleManage, $role->tenant()->firstOrFail());
    }

    public function delete(User $user, Role $role): bool
    {
        return ! $role->is_system
            && $this->permissions->allows($user, Permission::RoleManage, $role->tenant()->firstOrFail());
    }
}
