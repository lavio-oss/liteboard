<?php

namespace Modules\Admin\Policies\Asset;

use Modules\Admin\Models\Asset\Asset;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Enums\Role\Permission;
use Modules\Core\Models\User\User;
use Modules\Core\Services\Role\PermissionResolver;

final class AssetPolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $this->permissions->allows($user, Permission::AssetView, $project);
    }

    public function view(User $user, Asset $asset): bool
    {
        return $this->permissions->allows($user, Permission::AssetView, $asset->project()->firstOrFail());
    }

    public function create(User $user, Project $project): bool
    {
        return $this->permissions->allows($user, Permission::AssetWrite, $project);
    }

    public function update(User $user, Asset $asset): bool
    {
        return $this->permissions->allows($user, Permission::AssetWrite, $asset->project()->firstOrFail());
    }

    public function delete(User $user, Asset $asset): bool
    {
        return $this->permissions->allows($user, Permission::AssetDelete, $asset->project()->firstOrFail());
    }
}
