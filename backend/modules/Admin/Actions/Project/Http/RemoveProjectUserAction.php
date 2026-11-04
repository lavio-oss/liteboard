<?php

namespace Modules\Admin\Actions\Project\Http;

use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Project\RemoveProjectUserRequest;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class RemoveProjectUserAction
{
    public function __invoke(
        RemoveProjectUserRequest $request,
        Tenant $tenant,
        Project $project,
        User $user,
        Responder $responder,
    ): Response {
        $project->users()->detach($user->getKey());

        return $responder->noContent();
    }
}
