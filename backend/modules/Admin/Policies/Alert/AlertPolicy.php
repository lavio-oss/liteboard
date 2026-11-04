<?php

namespace Modules\Admin\Policies\Alert;

use Modules\Admin\Models\Alert\Alert;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Enums\Role\Permission;
use Modules\Core\Models\User\User;
use Modules\Core\Services\Role\PermissionResolver;

final class AlertPolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $this->permissions->allows($user, Permission::AlertView, $project);
    }

    public function view(User $user, Alert $alert): bool
    {
        return $this->permissions->allows($user, Permission::AlertView, $alert->project()->firstOrFail());
    }

    public function acknowledge(User $user, Alert $alert): bool
    {
        return $this->permissions->allows($user, Permission::AlertAcknowledge, $alert->project()->firstOrFail());
    }
}
