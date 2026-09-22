<?php

namespace Modules\Masterdata\Actions\Project\Http;

use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Shared\Responders\Responder;
use Modules\Masterdata\Actions\Project\UpdateProjectUserRoleAction;
use Modules\Masterdata\Enums\Project\ProjectRole;
use Modules\Masterdata\Models\Project\Project;
use Modules\Masterdata\Requests\Project\UpdateProjectUserRequest;
use Modules\Masterdata\Resources\Project\ProjectUserResource;
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
