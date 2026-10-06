<?php

namespace Modules\Admin\Actions\DeviceProvisioning\Http;

use LogicException;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\DeviceProvisioning\UpdateProjectProvisioningRequest;
use Modules\Admin\Resources\DeviceProvisioning\ProjectProvisioningProfileResource;
use Modules\Admin\Services\DeviceProvisioning\DeviceProvisioningException;
use Modules\Admin\Services\DeviceProvisioning\DeviceProvisioningService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class UpdateProjectProvisioningProfileAction
{
    public function __invoke(
        UpdateProjectProvisioningRequest $request,
        Tenant $tenant,
        Project $project,
        DeviceProvisioningService $service,
        Responder $responder,
    ): Response {
        $validated = $request->validated();
        $user = $request->user();

        if (! $user instanceof User || ! is_bool($validated['enabled'])) {
            throw new LogicException('The validated profile status and actor are required.');
        }

        try {
            $profile = $service->updateProfile($project, $validated['enabled'], (string) $user->getKey());
        } catch (DeviceProvisioningException $exception) {
            return $responder->json(['code' => $exception->getMessage()], $exception->status);
        }

        return $responder->resource(ProjectProvisioningProfileResource::make($profile));
    }
}
