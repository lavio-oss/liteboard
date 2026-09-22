<?php

namespace Modules\Masterdata\Actions\Project\Http;

use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Shared\Responders\Responder;
use Modules\Masterdata\Models\Project\Project;
use Modules\Masterdata\Requests\Project\AuthenticatedRequest;
use Symfony\Component\HttpFoundation\Response;

final class RemoveProjectUserAction
{
    public function __invoke(
        AuthenticatedRequest $request,
        Tenant $tenant,
        Project $project,
        User $user,
        Responder $responder,
    ): Response {
        Gate::forUser($request->user())->authorize('manageUsers', $project);
        $project->users()->detach($user->getKey());

        return $responder->noContent();
    }
}
