<?php

namespace Modules\Admin\Policies\Project;

use Modules\Admin\Models\Project\Project;
use Modules\Core\Enums\Role\Permission;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Services\Role\PermissionResolver;

final class ProjectPolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user, Tenant $tenant): bool
    {
        return $tenant->hasMember($user);
    }

    public function view(User $user, Project $project): bool
    {
        return $this->permissions->allows($user, Permission::ProjectView, $project);
    }

    public function create(User $user, Tenant $tenant): bool
    {
        return $this->permissions->allows($user, Permission::ProjectCreate, $tenant);
    }

    public function update(User $user, Project $project): bool
    {
        return $this->permissions->allows($user, Permission::ProjectUpdate, $project);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->permissions->allows($user, Permission::ProjectDelete, $project);
    }

    public function manageUsers(User $user, Project $project): bool
    {
        return $this->permissions->allows($user, Permission::ProjectMemberManage, $project);
    }

    public function manageProfile(User $user, Project $project): bool
    {
        return $this->permissions->allows($user, Permission::ProjectProvisioningManage, $project);
    }

    public function manageQuota(User $user, Project $project): bool
    {
        return $this->permissions->allows($user, Permission::ProjectQuotaManage, $project);
    }
}
