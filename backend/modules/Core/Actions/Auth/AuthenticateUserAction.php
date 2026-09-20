<?php

namespace Modules\Core\Actions\Auth;

use Illuminate\Support\Facades\Hash;
use Modules\Core\Models\User\User;

final class AuthenticateUserAction
{
    public function execute(string $email, string $password): ?User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            return null;
        }

        return $user;
    }
}
