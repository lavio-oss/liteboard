<?php

namespace Modules\Masterdata\Actions\Project;

use Modules\Masterdata\Models\Project\Project;

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
