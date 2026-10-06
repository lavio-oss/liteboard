<?php

namespace Modules\Admin\Policies\Device;

use Modules\Admin\Enums\Device\DeviceApprovalStatus;
use Modules\Admin\Enums\Project\ProjectRole;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Enums\Tenant\TenantRole;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

final class DevicePolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $this->projectAllowsView($user, $project);
    }

    public function view(User $user, Device $device): bool
    {
        return $this->projectAllowsView($user, $this->projectFor($device));
    }

    public function create(User $user, Project $project): bool
    {
        return $this->projectAllowsWrite($user, $project);
    }

    public function update(User $user, Device $device): bool
    {
        return $this->projectAllowsWrite($user, $this->projectFor($device));
    }

    public function manageCredentials(User $user, Device $device): bool
    {
        return $device->getAttribute('approval_status') === DeviceApprovalStatus::Approved
            && $this->projectAllowsWrite($user, $this->projectFor($device));
    }

    public function approve(User $user, Device $device): bool
    {
        return $this->projectAllowsWrite($user, $this->projectFor($device));
    }

    public function reject(User $user, Device $device): bool
    {
        return $this->projectAllowsWrite($user, $this->projectFor($device));
    }

    public function delete(User $user, Device $device): bool
    {
        return $this->projectAllowsDelete($user, $this->projectFor($device));
    }

    private function tenantFor(Project $project): Tenant
    {
        return $project->tenant()->firstOrFail();
    }

    private function projectFor(Device $device): Project
    {
        return $device->project()->firstOrFail();
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
