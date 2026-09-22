<?php

namespace Modules\Masterdata\Actions\Project\Http;

use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Modules\Masterdata\Actions\Project\CreateProjectUserAccountAction as CreateProjectUserAccount;
use Modules\Masterdata\Models\Project\Project;
use Modules\Masterdata\Requests\Project\StoreProjectUserAccountRequest;
use Modules\Masterdata\Resources\Project\ProjectUserResource;
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
