<?php

namespace Modules\Admin\Actions\Project;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Enums\Role\DefaultRole;
use Modules\Core\Models\Role\Role;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

final class CreateProjectUserAccountAction
{
    /**
     * @param  array{name: string, email: string, password: string}  $attributes
     */
    public function execute(Tenant $tenant, Project $project, Role $role, array $attributes): User
    {
        return DB::transaction(function () use ($tenant, $project, $role, $attributes): User {
            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'],
            ]);

            $tenant->users()->attach($user->getKey(), ['role_id' => $tenant->defaultRole(DefaultRole::TenantMember)->getKey()]);
            $project->users()->attach($user->getKey(), ['role_id' => $role->getKey()]);

            return $project->users()->whereKey($user->getKey())->firstOrFail();
        });
    }
}
