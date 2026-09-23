<?php

namespace Modules\Admin\Actions\Project;

use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\Tenant\Tenant;

final class CreateProjectAction
{
    /**
     * @param  array{name: string, description?: string|null, status?: string}  $attributes
     */
    public function execute(Tenant $tenant, array $attributes): Project
    {
        $project = new Project;
        $project->tenant()->associate($tenant);
        $project->fill($attributes);
        $project->save();

        return $project->refresh();
    }
}
