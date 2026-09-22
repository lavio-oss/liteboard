<?php

namespace Modules\Masterdata\Actions\Project;

use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\Tenant\TenantRole;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Masterdata\Enums\Project\ProjectRole;
use Modules\Masterdata\Models\Project\Project;

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
