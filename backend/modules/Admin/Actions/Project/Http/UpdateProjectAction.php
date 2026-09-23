<?php

namespace Modules\Admin\Actions\Project\Http;

use Modules\Admin\Actions\Project\UpdateProjectAction as UpdateProject;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Project\UpdateProjectRequest;
use Modules\Admin\Resources\Project\ProjectResource;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class UpdateProjectAction
{
    public function __invoke(
        UpdateProjectRequest $request,
        Tenant $tenant,
        Project $project,
        UpdateProject $updateProject,
        Responder $responder,
    ): Response {
        /** @var array{name?: string, description?: string|null, status?: string} $attributes */
        $attributes = $request->validated();
        $project = $updateProject->execute($project, $attributes);

        return $responder->resource(ProjectResource::make($project));
    }
}
