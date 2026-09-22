<?php

namespace Modules\Core\Actions\Auth\Http;

use Modules\Core\Models\User\User;
use Modules\Core\Requests\Auth\AuthenticatedRequest;
use Modules\Core\Resources\Auth\PersonalAccessTokenResource;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ListPersonalAccessTokensAction
{
    public function __invoke(AuthenticatedRequest $request, Responder $responder): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $tokens = $user->tokens()->latest('id')->get();

        return $responder->resource(PersonalAccessTokenResource::collection($tokens));
    }
}
