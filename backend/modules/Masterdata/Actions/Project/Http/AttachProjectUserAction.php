<?php

namespace Modules\Masterdata\Actions\Project\Http;

use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Shared\Responders\Responder;
use Modules\Masterdata\Actions\Project\AttachProjectUserAction as AttachProjectUser;
use Modules\Masterdata\Enums\Project\ProjectRole;
use Modules\Masterdata\Models\Project\Project;
use Modules\Masterdata\Requests\Project\StoreProjectUserRequest;
use Modules\Masterdata\Resources\Project\ProjectUserResource;
use Symfony\Component\HttpFoundation\Response;

final class AttachProjectUserAction
{
    public function __invoke(
        StoreProjectUserRequest $request,
        Tenant $tenant,
        Project $project,
        AttachProjectUser $attachProjectUser,
        Responder $responder,
    ): Response {
        /** @var array{user_id: int, role: string} $attributes */
        $attributes = $request->validated();
        $user = User::query()->findOrFail($attributes['user_id']);
        $projectUser = $attachProjectUser->execute(
            $project,
            $user,
            ProjectRole::from($attributes['role']),
        );

        return $responder->resource(ProjectUserResource::make($projectUser), Response::HTTP_CREATED);
    }
}
