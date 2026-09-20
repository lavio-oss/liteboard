<?php

namespace Modules\Core\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Core\Actions\Auth\CreatePersonalAccessTokenAction;
use Modules\Core\Actions\Auth\RevokePersonalAccessTokenAction;
use Modules\Core\Actions\Auth\RotatePersonalAccessTokenAction;
use Modules\Core\Controllers\Controller;
use Modules\Core\Models\User\User;
use Modules\Core\Requests\Auth\AuthenticatedRequest;
use Modules\Core\Requests\Auth\CreateTokenRequest;
use Modules\Core\Resources\Auth\PersonalAccessTokenResource;
use Symfony\Component\HttpFoundation\Response;

final class PersonalAccessTokenController extends Controller
{
    public function index(AuthenticatedRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $tokens = $user->tokens()
            ->latest('id')
            ->get();

        return PersonalAccessTokenResource::collection($tokens);
    }

    public function store(
        CreateTokenRequest $request,
        CreatePersonalAccessTokenAction $createToken,
    ): JsonResponse {
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

        return response()->json([
            'data' => [
                ...$metadata,
                'token' => $newToken->plainTextToken,
            ],
        ], Response::HTTP_CREATED);
    }

    public function destroy(
        AuthenticatedRequest $request,
        int $token,
        RevokePersonalAccessTokenAction $revokeToken,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        if (! $revokeToken->execute($user, $token)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return response()->noContent();
    }

    public function rotate(
        AuthenticatedRequest $request,
        int $token,
        RotatePersonalAccessTokenAction $rotateToken,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $newToken = $rotateToken->execute($user, $token);

        if ($newToken === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $metadata = (new PersonalAccessTokenResource($newToken->accessToken))->resolve($request);

        return response()->json([
            'data' => [
                ...$metadata,
                'token' => $newToken->plainTextToken,
            ],
        ]);
    }
}
