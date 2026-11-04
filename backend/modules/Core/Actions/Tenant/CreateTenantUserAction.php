<?php

namespace Modules\Core\Actions\Tenant;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

final class CreateTenantUserAction
{
    /**
     * @param  array{name: string, email: string, password: string, role_id: string}  $attributes
     */
    public function execute(Tenant $tenant, array $attributes): User
    {
        return DB::transaction(function () use ($tenant, $attributes): User {
            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'],
            ]);

            $tenant->users()->attach($user->getKey(), ['role_id' => $attributes['role_id']]);

            return $tenant->users()->whereKey($user->getKey())->firstOrFail();
        });
    }
}
