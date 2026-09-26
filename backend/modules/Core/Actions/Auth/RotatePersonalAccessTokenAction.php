<?php

namespace Modules\Core\Actions\Auth;

use Illuminate\Database\DatabaseManager;
use Laravel\Sanctum\NewAccessToken;
use Modules\Core\Models\User\User;

final class RotatePersonalAccessTokenAction
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly CreatePersonalAccessTokenAction $createToken,
    ) {}

    public function execute(User $user, string $tokenId): ?NewAccessToken
    {
        return $this->database->transaction(function () use ($user, $tokenId): ?NewAccessToken {
            $token = $user->tokens()->whereKey($tokenId)->first();

            if ($token === null || ($token->expires_at !== null && $token->expires_at->isPast())) {
                return null;
            }

            $abilities = is_array($token->abilities) ? $token->abilities : [];
            $expiresInDays = $token->expires_at === null
                ? null
                : max(1, now()->diffInDays($token->expires_at));
            $newToken = $this->createToken->execute(
                $user,
                (string) $token->name,
                $abilities,
                $expiresInDays,
            );

            $token->delete();

            return $newToken;
        });
    }
}
