<?php

namespace Modules\Admin\Actions\Project;

use Modules\Admin\Models\Project\Project;

final class UpdateProjectAction
{
    /**
     * @param  array{name?: string, description?: string|null, status?: string}  $attributes
     */
    public function execute(Project $project, array $attributes): Project
    {
        $project->fill($attributes);
        $project->save();

        return $project->refresh();
    }
}
