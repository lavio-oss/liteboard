<?php

namespace Modules\Core\Policies\Tenant;

use Modules\Core\Enums\Tenant\TenantRole;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

final class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformAdmin() || $user->tenants()->exists();
    }

    public function view(User $user, Tenant $tenant): bool
    {
        return $user->isPlatformAdmin() || $tenant->hasMember($user);
    }

    public function create(User $user): bool
    {
        return $user->isPlatformAdmin()
            || $user->tenants()->wherePivotIn('role', [
                TenantRole::Owner->value,
                TenantRole::Admin->value,
            ])->exists();
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $user->isPlatformAdmin()
            || $tenant->hasRole($user, TenantRole::Owner, TenantRole::Admin);
    }

    public function delete(User $user, Tenant $tenant): bool
    {
        return $user->isPlatformAdmin() || $tenant->hasRole($user, TenantRole::Owner);
    }

    public function manageUsers(User $user, Tenant $tenant): bool
    {
        return $user->isPlatformAdmin()
            || $tenant->hasRole($user, TenantRole::Owner, TenantRole::Admin);
    }
}
