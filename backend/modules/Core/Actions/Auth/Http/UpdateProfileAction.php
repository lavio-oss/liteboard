<?php

namespace Modules\Core\Actions\Auth\Http;

use Modules\Core\Actions\Auth\UpdateProfileAction as UpdateProfile;
use Modules\Core\Models\User\User;
use Modules\Core\Requests\Auth\UpdateProfileRequest;
use Modules\Core\Resources\Auth\UserResource;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class UpdateProfileAction
{
    public function __invoke(
        UpdateProfileRequest $request,
        UpdateProfile $updateProfile,
        Responder $responder,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        /** @var array{name: string, email: string} $attributes */
        $attributes = $request->validated();
        $user = $updateProfile->execute($user, $attributes);

        return $responder->resource(UserResource::make($user));
    }
}
