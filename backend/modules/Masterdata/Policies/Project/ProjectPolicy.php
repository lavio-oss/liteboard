<?php

namespace Modules\Masterdata\Policies\Project;

use Modules\Core\Enums\Tenant\TenantRole;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Masterdata\Enums\Project\ProjectRole;
use Modules\Masterdata\Models\Project\Project;

final class ProjectPolicy
{
    public function viewAny(User $user, Tenant $tenant): bool
    {
        return $user->isPlatformAdmin() || $tenant->hasMember($user);
    }

    public function view(User $user, Project $project): bool
    {
        return $this->tenantAllows($user, $project) || $project->hasMember($user);
    }

    public function create(User $user, Tenant $tenant): bool
    {
        return $user->isPlatformAdmin()
            || $tenant->hasRole($user, TenantRole::Owner, TenantRole::Admin);
    }

    public function update(User $user, Project $project): bool
    {
        return $this->tenantAllows($user, $project)
            || $project->hasRole($user, ProjectRole::Admin, ProjectRole::Engineer);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->tenantAllows($user, $project)
            || $project->hasRole($user, ProjectRole::Admin);
    }

    public function manageUsers(User $user, Project $project): bool
    {
        return $this->tenantAllows($user, $project);
    }

    private function tenantAllows(User $user, Project $project): bool
    {
        return $user->isPlatformAdmin()
            || $this->tenantFor($project)->hasRole($user, TenantRole::Owner, TenantRole::Admin);
    }

    private function tenantFor(Project $project): Tenant
    {
        return $project->tenant()->firstOrFail();
    }
}
