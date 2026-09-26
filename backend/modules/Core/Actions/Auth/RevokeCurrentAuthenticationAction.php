<?php

namespace Modules\Core\Actions\Auth;

use Illuminate\Auth\AuthManager;
use Modules\Core\Models\Auth\PersonalAccessToken;
use Modules\Core\Models\User\User;
use Modules\Core\Requests\Auth\AuthenticatedRequest;

final class RevokeCurrentAuthenticationAction
{
    public function __construct(private readonly AuthManager $auth) {}

    public function execute(AuthenticatedRequest $request, User $user): void
    {
        $token = $user->currentAccessToken();

        if (get_class($token) === PersonalAccessToken::class) {
            $token->delete();
        }

        if ($request->hasSession()) {
            $this->auth->guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
