<?php

namespace Modules\Core\Actions\Auth\Http;

use Modules\Core\Actions\Auth\RevokeCurrentAuthenticationAction;
use Modules\Core\Models\User\User;
use Modules\Core\Requests\Auth\AuthenticatedRequest;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class LogoutAction
{
    public function __invoke(
        AuthenticatedRequest $request,
        RevokeCurrentAuthenticationAction $revokeCurrent,
        Responder $responder,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $revokeCurrent->execute($request, $user);

        return $responder->noContent();
    }
}
