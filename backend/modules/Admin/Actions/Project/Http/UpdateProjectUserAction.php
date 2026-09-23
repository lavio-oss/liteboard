<?php

namespace Modules\Admin\Actions\Project\Http;

use Modules\Admin\Actions\Project\UpdateProjectUserRoleAction;
use Modules\Admin\Enums\Project\ProjectRole;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Project\UpdateProjectUserRequest;
use Modules\Admin\Resources\Project\ProjectUserResource;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class UpdateProjectUserAction
{
    public function __invoke(
        UpdateProjectUserRequest $request,
        Tenant $tenant,
        Project $project,
        User $user,
        UpdateProjectUserRoleAction $updateProjectUserRole,
        Responder $responder,
    ): Response {
        /** @var array{role: string} $attributes */
        $attributes = $request->validated();
        $projectUser = $updateProjectUserRole->execute(
            $project,
            $user,
            ProjectRole::from($attributes['role']),
        );

        return $responder->resource(ProjectUserResource::make($projectUser));
    }
}
