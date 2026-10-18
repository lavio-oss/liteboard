<?php

namespace Modules\Admin\Policies\Alert;

use Modules\Admin\Enums\Project\ProjectRole;
use Modules\Admin\Models\Alert\Alert;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Enums\Tenant\TenantRole;
use Modules\Core\Models\User\User;

final class AlertPolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $user->isPlatformAdmin()
            || $project->tenant()->firstOrFail()->hasRole($user, TenantRole::Owner, TenantRole::Admin)
            || $project->hasMember($user);
    }

    public function view(User $user, Alert $alert): bool
    {
        return $this->viewAny($user, $alert->project()->firstOrFail());
    }

    public function acknowledge(User $user, Alert $alert): bool
    {
        $project = $alert->project()->firstOrFail();

        return $user->isPlatformAdmin()
            || $project->tenant()->firstOrFail()->hasRole($user, TenantRole::Owner, TenantRole::Admin)
            || $project->hasRole($user, ProjectRole::Admin, ProjectRole::Engineer);
    }
}
