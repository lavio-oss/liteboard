<?php

namespace Modules\Masterdata\Actions\Project\Http;

use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Modules\Masterdata\Actions\Project\CreateProjectAction as CreateProject;
use Modules\Masterdata\Requests\Project\StoreProjectRequest;
use Modules\Masterdata\Resources\Project\ProjectResource;
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
