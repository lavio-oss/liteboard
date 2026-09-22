<?php

namespace Modules\Masterdata\Actions\Project\Http;

use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Modules\Masterdata\Actions\Project\UpdateProjectAction as UpdateProject;
use Modules\Masterdata\Models\Project\Project;
use Modules\Masterdata\Requests\Project\UpdateProjectRequest;
use Modules\Masterdata\Resources\Project\ProjectResource;
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
