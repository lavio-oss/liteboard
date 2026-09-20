<?php

namespace Modules\Core\Actions\Auth;

use Modules\Core\Models\User\User;

final class RevokePersonalAccessTokenAction
{
    public function execute(User $user, int $tokenId): bool
    {
        $token = $user->tokens()->whereKey($tokenId)->first();

        if ($token === null) {
            return false;
        }

        $token->delete();

        return true;
    }
}
