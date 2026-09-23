<?php

namespace Modules\Admin\Actions\Project;

use Modules\Admin\Enums\Project\ProjectRole;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\User\User;

final class AttachProjectUserAction
{
    public function execute(Project $project, User $user, ProjectRole $role): User
    {
        $project->users()->attach($user->getKey(), ['role' => $role->value]);

        return $project->users()->whereKey($user->getKey())->firstOrFail();
    }
}
