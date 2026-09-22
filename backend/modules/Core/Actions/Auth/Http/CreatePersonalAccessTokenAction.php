<?php

namespace Modules\Core\Actions\Auth\Http;

use Modules\Core\Actions\Auth\CreatePersonalAccessTokenAction as CreateToken;
use Modules\Core\Models\User\User;
use Modules\Core\Requests\Auth\CreateTokenRequest;
use Modules\Core\Resources\Auth\PersonalAccessTokenResource;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class CreatePersonalAccessTokenAction
{
    public function __invoke(
        CreateTokenRequest $request,
        CreateToken $createToken,
        Responder $responder,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        /** @var array{name: string, abilities: array<int, string>, expires_in_days?: int|null} $data */
        $data = $request->validated();
        $newToken = $createToken->execute(
            $user,
            $data['name'],
            array_values($data['abilities']),
            $data['expires_in_days'] ?? null,
        );

        $metadata = (new PersonalAccessTokenResource($newToken->accessToken))->resolve($request);

        return $responder->json([
            'data' => [
                ...$metadata,
                'token' => $newToken->plainTextToken,
            ],
        ], Response::HTTP_CREATED);
    }
}
