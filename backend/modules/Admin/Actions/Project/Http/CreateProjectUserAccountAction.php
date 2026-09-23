<?php

namespace Modules\Admin\Actions\Project\Http;

use Modules\Admin\Actions\Project\CreateProjectUserAccountAction as CreateProjectUserAccount;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Project\StoreProjectUserAccountRequest;
use Modules\Admin\Resources\Project\ProjectUserResource;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class CreateProjectUserAccountAction
{
    public function __invoke(
        StoreProjectUserAccountRequest $request,
        Tenant $tenant,
        Project $project,
        CreateProjectUserAccount $createProjectUserAccount,
        Responder $responder,
    ): Response {
        /** @var array{name: string, email: string, password: string, role: string} $attributes */
        $attributes = $request->validated();
        $projectUser = $createProjectUserAccount->execute($tenant, $project, $attributes);

        return $responder->resource(ProjectUserResource::make($projectUser), Response::HTTP_CREATED);
    }
}
