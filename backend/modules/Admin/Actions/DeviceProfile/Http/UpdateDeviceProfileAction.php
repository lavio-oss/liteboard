<?php

namespace Modules\Admin\Actions\DeviceProfile\Http;

use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\DeviceProfile\UpdateDeviceProfileRequest;
use Modules\Admin\Resources\DeviceProfile\DeviceProfileResource;
use Modules\Admin\Services\DeviceProfile\DeviceProfilePersistenceService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class UpdateDeviceProfileAction
{
    public function __invoke(
        UpdateDeviceProfileRequest $request,
        Tenant $tenant,
        Project $project,
        DeviceProfile $deviceProfile,
        DeviceProfilePersistenceService $deviceProfilePersistenceService,
        Responder $responder,
    ): Response {
        $deviceProfile = $deviceProfilePersistenceService->update(
            $deviceProfile,
            $request->profileAttributes(),
        );

        return $responder->resource(DeviceProfileResource::make($deviceProfile));
    }
}
