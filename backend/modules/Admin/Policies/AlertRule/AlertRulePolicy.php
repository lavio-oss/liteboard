<?php

namespace Modules\Admin\Policies\AlertRule;

use Modules\Admin\Models\AlertRule\AlertRule;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Enums\Role\Permission;
use Modules\Core\Models\User\User;
use Modules\Core\Services\Role\PermissionResolver;

final class AlertRulePolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user, Project $project): bool
    {
        return $this->permissions->allows($user, Permission::AlertRuleView, $project);
    }

    public function view(User $user, AlertRule $alertRule): bool
    {
        return $this->permissions->allows($user, Permission::AlertRuleView, $alertRule->project()->firstOrFail());
    }

    public function create(User $user, Project $project): bool
    {
        return $this->permissions->allows($user, Permission::AlertRuleWrite, $project);
    }

    public function update(User $user, AlertRule $alertRule): bool
    {
        return $this->permissions->allows($user, Permission::AlertRuleWrite, $alertRule->project()->firstOrFail());
    }
}
