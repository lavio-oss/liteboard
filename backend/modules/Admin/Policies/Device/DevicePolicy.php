<?php

namespace Modules\Admin\Policies\Device;

use Modules\Admin\Enums\Device\DeviceApprovalStatus;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Enums\Role\Permission;
use Modules\Core\Models\User\User;
use Modules\Core\Services\Role\PermissionResolver;

final class DevicePolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $this->permissions->allows($user, Permission::DeviceView, $project);
    }

    public function view(User $user, Device $device): bool
    {
        return $this->permissions->allows($user, Permission::DeviceView, $device->project()->firstOrFail());
    }

    public function create(User $user, Project $project): bool
    {
        return $this->permissions->allows($user, Permission::DeviceWrite, $project);
    }

    public function update(User $user, Device $device): bool
    {
        return $this->permissions->allows($user, Permission::DeviceWrite, $device->project()->firstOrFail());
    }

    public function manageCredentials(User $user, Device $device): bool
    {
        return $device->getAttribute('approval_status') === DeviceApprovalStatus::Approved
            && $this->permissions->allows($user, Permission::DeviceCredentialManage, $device->project()->firstOrFail());
    }

    public function approve(User $user, Device $device): bool
    {
        return $this->permissions->allows($user, Permission::DeviceWrite, $device->project()->firstOrFail());
    }

    public function reject(User $user, Device $device): bool
    {
        return $this->permissions->allows($user, Permission::DeviceWrite, $device->project()->firstOrFail());
    }

    public function delete(User $user, Device $device): bool
    {
        return $this->permissions->allows($user, Permission::DeviceDelete, $device->project()->firstOrFail());
    }
}
