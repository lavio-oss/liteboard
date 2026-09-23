<?php

namespace Modules\Admin\Actions\Project;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Enums\Project\ProjectRole;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Enums\Tenant\TenantRole;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

final class CreateProjectUserAccountAction
{
    /**
     * @param  array{name: string, email: string, password: string, role: string}  $attributes
     */
    public function execute(Tenant $tenant, Project $project, array $attributes): User
    {
        return DB::transaction(function () use ($tenant, $project, $attributes): User {
            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'],
            ]);

            $tenant->users()->attach($user->getKey(), ['role' => TenantRole::Member->value]);
            $project->users()->attach($user->getKey(), [
                'role' => ProjectRole::from($attributes['role'])->value,
            ]);

            return $project->users()->whereKey($user->getKey())->firstOrFail();
        });
    }
}
