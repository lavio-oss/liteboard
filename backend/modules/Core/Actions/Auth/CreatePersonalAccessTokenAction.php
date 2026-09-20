<?php

namespace Modules\Core\Actions\Auth;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Laravel\Sanctum\NewAccessToken;
use Modules\Core\Models\User\User;

final class CreatePersonalAccessTokenAction
{
    /**
     * @param  array<int, string>  $abilities
     */
    public function execute(User $user, string $name, array $abilities, ?int $expiresInDays): NewAccessToken
    {
        $expirationDays = $expiresInDays ?? (int) config('sanctum.default_token_expiration_days');
        $maxExpirationDays = (int) config('sanctum.max_token_expiration_days');

        if ($expirationDays < 1 || $expirationDays > $maxExpirationDays) {
            throw new InvalidArgumentException('The requested token expiration is outside the allowed range.');
        }

        return $user->createToken(
            $name,
            array_values(array_unique($abilities)),
            CarbonImmutable::now()->addDays($expirationDays),
        );
    }
}
