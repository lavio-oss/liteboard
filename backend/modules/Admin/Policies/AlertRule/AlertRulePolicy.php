<?php

namespace Modules\Admin\Policies\AlertRule;

use Modules\Admin\Enums\Project\ProjectRole;
use Modules\Admin\Models\AlertRule\AlertRule;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Enums\Tenant\TenantRole;
use Modules\Core\Models\User\User;

final class AlertRulePolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $this->projectAllowsView($user, $project);
    }

    public function view(User $user, AlertRule $alertRule): bool
    {
        return $this->projectAllowsView($user, $alertRule->project()->firstOrFail());
    }

    public function create(User $user, Project $project): bool
    {
        return $this->projectAllowsWrite($user, $project);
    }

    public function update(User $user, AlertRule $alertRule): bool
    {
        return $this->projectAllowsWrite($user, $alertRule->project()->firstOrFail());
    }

    private function projectAllowsView(User $user, Project $project): bool
    {
        return $user->isPlatformAdmin()
            || $project->tenant()->firstOrFail()->hasRole($user, TenantRole::Owner, TenantRole::Admin)
            || $project->hasMember($user);
    }

    private function projectAllowsWrite(User $user, Project $project): bool
    {
        return $user->isPlatformAdmin()
            || $project->tenant()->firstOrFail()->hasRole($user, TenantRole::Owner, TenantRole::Admin)
            || $project->hasRole($user, ProjectRole::Admin, ProjectRole::Engineer);
    }
}
