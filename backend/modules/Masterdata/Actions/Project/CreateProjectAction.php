<?php

namespace Modules\Masterdata\Actions\Project;

use Modules\Core\Models\Tenant\Tenant;
use Modules\Masterdata\Models\Project\Project;

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
