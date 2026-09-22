<?php

namespace Modules\Core\Actions\Auth\Http;

use Modules\Core\Actions\Auth\RotatePersonalAccessTokenAction as RotateToken;
use Modules\Core\Models\User\User;
use Modules\Core\Requests\Auth\AuthenticatedRequest;
use Modules\Core\Resources\Auth\PersonalAccessTokenResource;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class RotatePersonalAccessTokenAction
{
    public function __invoke(
        AuthenticatedRequest $request,
        int $token,
        RotateToken $rotateToken,
        Responder $responder,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $newToken = $rotateToken->execute($user, $token);

        if ($newToken === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $metadata = (new PersonalAccessTokenResource($newToken->accessToken))->resolve($request);

        return $responder->json([
            'data' => [
                ...$metadata,
                'token' => $newToken->plainTextToken,
            ],
        ]);
    }
}
