<?php

namespace Modules\Masterdata\Actions\Project\Http;

use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Shared\Responders\Responder;
use Modules\Masterdata\Models\Project\Project;
use Modules\Masterdata\Requests\Project\AuthenticatedRequest;
use Modules\Masterdata\Resources\Project\ProjectResource;
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
