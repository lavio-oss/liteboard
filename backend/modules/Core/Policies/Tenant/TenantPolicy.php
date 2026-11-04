<?php

namespace Modules\Core\Policies\Tenant;

use Modules\Core\Enums\Role\Permission;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Services\Role\PermissionResolver;

final class TenantPolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function view(User $user, Tenant $tenant): bool
    {
        return $tenant->hasMember($user);
    }

    public function viewTenantAssets(User $user, Tenant $tenant): bool
    {
        return $this->permissions->allows($user, Permission::AssetView, $tenant);
    }

    public function viewTenantDevices(User $user, Tenant $tenant): bool
    {
        return $this->permissions->allows($user, Permission::DeviceView, $tenant);
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $this->permissions->allows($user, Permission::TenantUpdate, $tenant);
    }

    public function manageUsers(User $user, Tenant $tenant): bool
    {
        return $this->permissions->allows($user, Permission::UserManage, $tenant);
    }

    /**
     * Nobody edits or removes themselves here (that is what /me is for), and only an Owner touches an Owner.
     */
    public function manageMember(User $user, Tenant $tenant, User $member): bool
    {
        return $this->manageUsers($user, $tenant)
            && $user->isNot($member)
            && (! $this->permissions->isOwner($member, $tenant) || $this->permissions->isOwner($user, $tenant));
    }

    public function manageRoles(User $user, Tenant $tenant): bool
    {
        return $this->permissions->allows($user, Permission::RoleManage, $tenant);
    }
}
