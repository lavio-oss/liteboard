<?php

namespace Modules\Masterdata\Actions\Project;

use Modules\Core\Models\User\User;
use Modules\Masterdata\Enums\Project\ProjectRole;
use Modules\Masterdata\Models\Project\Project;

final class UpdateProjectUserRoleAction
{
    public function execute(Project $project, User $user, ProjectRole $role): User
    {
        $project->users()->updateExistingPivot($user->getKey(), ['role' => $role->value]);

        return $project->users()->whereKey($user->getKey())->firstOrFail();
    }
}
