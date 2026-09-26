<?php

namespace Modules\Core\Actions\Auth\Http;

use Modules\Core\Actions\Auth\RevokePersonalAccessTokenAction as RevokeToken;
use Modules\Core\Models\User\User;
use Modules\Core\Requests\Auth\AuthenticatedRequest;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class RevokePersonalAccessTokenAction
{
    public function __invoke(
        AuthenticatedRequest $request,
        string $token,
        RevokeToken $revokeToken,
        Responder $responder,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        if (! $revokeToken->execute($user, $token)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $responder->noContent();
    }
}
