<?php

namespace Modules\Admin\Actions\DeviceProfile\Http;

use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\DeviceProfile\StoreDeviceProfileRequest;
use Modules\Admin\Resources\DeviceProfile\DeviceProfileResource;
use Modules\Admin\Services\DeviceProfile\DeviceProfilePersistenceService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class CreateDeviceProfileAction
{
    public function __invoke(
        StoreDeviceProfileRequest $request,
        Tenant $tenant,
        Project $project,
        DeviceProfilePersistenceService $deviceProfilePersistenceService,
        Responder $responder,
    ): Response {
        $profile = $deviceProfilePersistenceService->create($project, $request->profileAttributes());
        $profile->load('versions');

        return $responder->resource(DeviceProfileResource::make($profile), Response::HTTP_CREATED);
    }
}
