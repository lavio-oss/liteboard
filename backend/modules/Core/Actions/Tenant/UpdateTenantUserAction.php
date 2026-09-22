<?php

namespace Modules\Core\Actions\Tenant;

use Modules\Core\Models\User\User;

final class UpdateTenantUserAction
{
    /**
     * @param  array{name?: string, email?: string, password?: string|null}  $attributes
     */
    public function execute(User $user, array $attributes): User
    {
        $user->fill($attributes);

        if (array_key_exists('email', $attributes)) {
            $user->email_verified_at = null;
        }

        $user->save();

        return $user->refresh();
    }
}
