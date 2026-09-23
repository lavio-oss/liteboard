<?php

namespace Modules\Admin\Actions\Project\Http;

use Modules\Admin\Actions\Project\CreateProjectAction as CreateProject;
use Modules\Admin\Requests\Project\StoreProjectRequest;
use Modules\Admin\Resources\Project\ProjectResource;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class CreateProjectAction
{
    public function __invoke(
        StoreProjectRequest $request,
        Tenant $tenant,
        CreateProject $createProject,
        Responder $responder,
    ): Response {
        /** @var array{name: string, description?: string|null, status?: string} $attributes */
        $attributes = $request->validated();
        $project = $createProject->execute($tenant, $attributes);

        return $responder->resource(ProjectResource::make($project), Response::HTTP_CREATED);
    }
}
