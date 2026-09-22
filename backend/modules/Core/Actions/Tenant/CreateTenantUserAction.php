<?php

namespace Modules\Core\Actions\Tenant;

use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\Tenant\TenantRole;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

final class CreateTenantUserAction
{
    /**
     * @param  array{name: string, email: string, password: string}  $attributes
     */
    public function execute(Tenant $tenant, array $attributes): User
    {
        return DB::transaction(function () use ($tenant, $attributes): User {
            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'],
            ]);

            $tenant->users()->attach($user->getKey(), [
                'role' => TenantRole::Member->value,
            ]);

            return $tenant->users()->whereKey($user->getKey())->firstOrFail();
        });
    }
}
