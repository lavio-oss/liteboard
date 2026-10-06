<?php

namespace Modules\Admin\Actions\DeviceProvisioning\Http;

use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\DeviceProvisioning\ManageProjectQuotaRequest;
use Modules\Admin\Resources\Project\ProjectResource;
use Modules\Admin\Services\DeviceProvisioning\DeviceProvisioningException;
use Modules\Admin\Services\DeviceProvisioning\DeviceProvisioningService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class UpdateProjectDeviceQuotaAction
{
    public function __invoke(
        ManageProjectQuotaRequest $request,
        Tenant $tenant,
        Project $project,
        DeviceProvisioningService $service,
        Responder $responder,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        try {
            $updatedProject = $service->updateQuota($project, $request->quota(), (string) $user->getKey());
        } catch (DeviceProvisioningException $exception) {
            return $responder->json(['code' => $exception->getMessage()], $exception->status);
        }

        return $responder->resource(ProjectResource::make($updatedProject));
    }
}
