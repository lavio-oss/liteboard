<?php

namespace Modules\Admin\Actions\Project\Http;

use Modules\Admin\Actions\Project\UpdateProjectUserRoleAction;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Project\UpdateProjectUserRequest;
use Modules\Admin\Resources\Project\ProjectUserResource;
use Modules\Core\Models\Role\Role;
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
        /** @var array{role_id: string} $attributes */
        $attributes = $request->validated();
        $projectUser = $updateProjectUserRole->execute(
            $project,
            $user,
            Role::query()->findOrFail($attributes['role_id']),
        );

        return $responder->resource(ProjectUserResource::make($projectUser));
    }
}
