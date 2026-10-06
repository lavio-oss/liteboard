<?php

namespace Modules\Admin\Actions\DeviceProvisioning\Http;

use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\DeviceProvisioning\ManageProjectProvisioningRequest;
use Modules\Admin\Resources\DeviceProvisioning\ProjectProvisioningProfileResource;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ShowProjectProvisioningProfileAction
{
    public function __invoke(
        ManageProjectProvisioningRequest $request,
        Tenant $tenant,
        Project $project,
        Responder $responder,
    ): Response {
        $profile = $project->provisioningProfile()->first();

        return $profile === null
            ? $responder->json(['data' => null])
            : $responder->resource(ProjectProvisioningProfileResource::make($profile));
    }
}
