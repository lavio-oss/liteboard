<?php

namespace Modules\Core\Actions\Auth\Http;

use Modules\Core\Actions\Auth\ChangePasswordAction;
use Modules\Core\Models\User\User;
use Modules\Core\Requests\Auth\ChangePasswordRequest;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class UpdatePasswordAction
{
    public function __invoke(
        ChangePasswordRequest $request,
        ChangePasswordAction $changePassword,
        Responder $responder,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        /** @var array{password: string} $attributes */
        $attributes = $request->validated();
        $changePassword->execute($user, $attributes);

        return $responder->noContent();
    }
}
