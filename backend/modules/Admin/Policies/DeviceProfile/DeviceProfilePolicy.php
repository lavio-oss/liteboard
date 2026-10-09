<?php

namespace Modules\Admin\Policies\DeviceProfile;

use Modules\Admin\Enums\Project\ProjectRole;
use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Enums\Tenant\TenantRole;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

final class DeviceProfilePolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $this->projectAllowsView($user, $project);
    }

    public function view(User $user, DeviceProfile $deviceProfile): bool
    {
        return $this->projectAllowsView($user, $this->projectFor($deviceProfile));
    }

    public function create(User $user, Project $project): bool
    {
        return $this->projectAllowsWrite($user, $project);
    }

    public function update(User $user, DeviceProfile $deviceProfile): bool
    {
        return $this->projectAllowsWrite($user, $this->projectFor($deviceProfile));
    }

    public function delete(User $user, DeviceProfile $deviceProfile): bool
    {
        return $this->projectAllowsDelete($user, $this->projectFor($deviceProfile));
    }

    private function tenantFor(Project $project): Tenant
    {
        return $project->tenant()->firstOrFail();
    }

    private function projectFor(DeviceProfile $deviceProfile): Project
    {
        return $deviceProfile->project()->firstOrFail();
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
