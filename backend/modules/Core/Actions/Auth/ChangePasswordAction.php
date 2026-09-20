<?php

namespace Modules\Core\Actions\Auth;

use Modules\Core\Models\User\User;

final class ChangePasswordAction
{
    /**
     * @param  array{password: string}  $attributes
     */
    public function execute(User $user, array $attributes): void
    {
        $user->password = $attributes['password'];
        $user->save();
    }
}
