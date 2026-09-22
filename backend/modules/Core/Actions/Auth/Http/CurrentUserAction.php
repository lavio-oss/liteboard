<?php

namespace Modules\Core\Actions\Auth\Http;

use Modules\Core\Models\User\User;
use Modules\Core\Requests\Auth\AuthenticatedRequest;
use Modules\Core\Resources\Auth\UserResource;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class CurrentUserAction
{
    public function __invoke(AuthenticatedRequest $request, Responder $responder): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $user->load('tenants');

        return $responder->resource(UserResource::make($user));
    }
}
