<?php

namespace Modules\Admin\Actions\Project\Http;

use Illuminate\Support\Facades\Gate;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Project\AuthenticatedRequest;
use Modules\Admin\Resources\Project\ProjectResource;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ShowProjectAction
{
    public function __invoke(
        AuthenticatedRequest $request,
        Tenant $tenant,
        Project $project,
        Responder $responder,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        Gate::forUser($user)->authorize('view', $project);

        return $responder->resource(ProjectResource::make($project));
    }
}
