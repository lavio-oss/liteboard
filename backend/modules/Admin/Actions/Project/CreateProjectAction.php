<?php

namespace Modules\Admin\Actions\Project;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Enums\Role\DefaultRole;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

final class CreateProjectAction
{
    /**
     * The creator becomes the project's Admin, so they keep access whatever their tenant role grants.
     *
     * @param  array{name: string, description?: string|null, status?: string}  $attributes
     */
    public function execute(Tenant $tenant, User $creator, array $attributes): Project
    {
        return DB::transaction(function () use ($tenant, $creator, $attributes): Project {
            $project = new Project;
            $project->tenant()->associate($tenant);
            $project->fill($attributes);
            $project->save();

            $project->users()->attach($creator->getKey(), [
                'role_id' => $tenant->defaultRole(DefaultRole::ProjectAdmin)->getKey(),
            ]);

            return $project->refresh();
        });
    }
}
