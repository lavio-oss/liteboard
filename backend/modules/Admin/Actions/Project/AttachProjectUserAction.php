<?php

namespace Modules\Admin\Actions\Project;

use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\Role\Role;
use Modules\Core\Models\User\User;

final class AttachProjectUserAction
{
    public function execute(Project $project, User $user, Role $role): User
    {
        $project->users()->attach($user->getKey(), ['role_id' => $role->getKey()]);

        return $project->users()->whereKey($user->getKey())->firstOrFail();
    }
}
