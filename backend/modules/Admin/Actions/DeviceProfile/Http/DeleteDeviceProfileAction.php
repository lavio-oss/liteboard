<?php

namespace Modules\Admin\Actions\DeviceProfile\Http;

use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\DeviceProfile\DeleteDeviceProfileRequest;
use Modules\Admin\Services\DeviceProfile\DeviceProfilePersistenceService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class DeleteDeviceProfileAction
{
    public function __invoke(
        DeleteDeviceProfileRequest $request,
        Tenant $tenant,
        Project $project,
        DeviceProfile $deviceProfile,
        DeviceProfilePersistenceService $deviceProfilePersistenceService,
        Responder $responder,
    ): Response {
        $deviceProfilePersistenceService->delete($deviceProfile);

        return $responder->noContent();
    }
}
