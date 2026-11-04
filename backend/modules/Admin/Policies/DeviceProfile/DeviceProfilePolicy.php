<?php

namespace Modules\Admin\Policies\DeviceProfile;

use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Enums\Role\Permission;
use Modules\Core\Models\User\User;
use Modules\Core\Services\Role\PermissionResolver;

final class DeviceProfilePolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $this->permissions->allows($user, Permission::DeviceProfileView, $project);
    }

    public function view(User $user, DeviceProfile $deviceProfile): bool
    {
        return $this->permissions->allows($user, Permission::DeviceProfileView, $deviceProfile->project()->firstOrFail());
    }

    public function create(User $user, Project $project): bool
    {
        return $this->permissions->allows($user, Permission::DeviceProfileWrite, $project);
    }

    public function update(User $user, DeviceProfile $deviceProfile): bool
    {
        return $this->permissions->allows($user, Permission::DeviceProfileWrite, $deviceProfile->project()->firstOrFail());
    }

    public function delete(User $user, DeviceProfile $deviceProfile): bool
    {
        return $this->permissions->allows($user, Permission::DeviceProfileDelete, $deviceProfile->project()->firstOrFail());
    }
}
