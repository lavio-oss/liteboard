<?php

namespace Modules\Admin\Policies\Asset;

use Modules\Admin\Enums\Project\ProjectRole;
use Modules\Admin\Models\Asset\Asset;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Enums\Tenant\TenantRole;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

final class AssetPolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $this->projectAllowsView($user, $project);
    }

    public function view(User $user, Asset $asset): bool
    {
        return $this->projectAllowsView($user, $this->projectFor($asset));
    }

    public function create(User $user, Project $project): bool
    {
        return $this->projectAllowsWrite($user, $project);
    }

    public function update(User $user, Asset $asset): bool
    {
        return $this->projectAllowsWrite($user, $this->projectFor($asset));
    }

    public function delete(User $user, Asset $asset): bool
    {
        return $this->projectAllowsDelete($user, $this->projectFor($asset));
    }

    private function tenantFor(Project $project): Tenant
    {
        return $project->tenant()->firstOrFail();
    }

    private function projectFor(Asset $asset): Project
    {
        return $asset->project()->firstOrFail();
    }

    private function projectAllowsView(User $user, Project $project): bool
    {
        return $user->isPlatformAdmin()
            || $this->tenantFor($project)->hasRole($user, TenantRole::Owner, TenantRole::Admin)
            || $project->hasMember($user);
    }

    private function projectAllowsWrite(User $user, Project $project): bool
    {
        return $user->isPlatformAdmin()
            || $this->tenantFor($project)->hasRole($user, TenantRole::Owner, TenantRole::Admin)
            || $project->hasRole($user, ProjectRole::Admin, ProjectRole::Engineer);
    }

    private function projectAllowsDelete(User $user, Project $project): bool
    {
        return $user->isPlatformAdmin()
            || $this->tenantFor($project)->hasRole($user, TenantRole::Owner, TenantRole::Admin)
            || $project->hasRole($user, ProjectRole::Admin);
    }
}
