<?php

namespace Modules\Admin\Actions\Project\Http;

use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Project\ShowProjectRequest;
use Modules\Admin\Resources\Project\ProjectResource;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ShowProjectAction
{
    public function __invoke(
        ShowProjectRequest $request,
        Tenant $tenant,
        Project $project,
        Responder $responder,
    ): Response {
        return $responder->resource(ProjectResource::make($project));
    }
}
