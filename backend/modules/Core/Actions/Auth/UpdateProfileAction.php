<?php

namespace Modules\Core\Actions\Auth;

use Modules\Core\Models\User\User;

final class UpdateProfileAction
{
    /**
     * @param  array{name: string, email: string}  $attributes
     */
    public function execute(User $user, array $attributes): User
    {
        $emailChanged = $user->email !== $attributes['email'];

        $user->name = $attributes['name'];
        $user->email = $attributes['email'];

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();
        $user->load('tenants');

        return $user;
    }
}
