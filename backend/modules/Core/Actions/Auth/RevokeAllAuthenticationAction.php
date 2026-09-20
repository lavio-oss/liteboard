<?php

namespace Modules\Core\Actions\Auth;

use Illuminate\Auth\AuthManager;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User\User;
use Modules\Core\Requests\Auth\AuthenticatedRequest;

final class RevokeAllAuthenticationAction
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly DatabaseManager $database,
    ) {}

    public function execute(AuthenticatedRequest $request, User $user): void
    {
        $this->database->transaction(function () use ($user): void {
            $user->tokens()->delete();

            if (config('session.driver') === 'database') {
                $this->database->table((string) config('session.table', 'sessions'))
                    ->where('user_id', $user->getAuthIdentifier())
                    ->delete();
            }
        });

        if ($request->hasSession()) {
            $this->auth->guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
