<?php

namespace Modules\Core\Actions\Tenant;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

final class UpdateTenantUserAction
{
    /**
     * @param  array{name?: string, email?: string, password?: string|null, role_id?: string}  $attributes
     */
    public function execute(Tenant $tenant, User $user, array $attributes): User
    {
        return DB::transaction(function () use ($tenant, $user, $attributes): User {
            $user->fill(array_intersect_key($attributes, array_flip(['name', 'email', 'password'])));

            if (array_key_exists('email', $attributes)) {
                $user->email_verified_at = null;
            }

            $user->save();

            if (array_key_exists('role_id', $attributes)) {
                $tenant->users()->updateExistingPivot($user->getKey(), ['role_id' => $attributes['role_id']]);
            }

            return $tenant->users()->whereKey($user->getKey())->firstOrFail();
        });
    }
}
