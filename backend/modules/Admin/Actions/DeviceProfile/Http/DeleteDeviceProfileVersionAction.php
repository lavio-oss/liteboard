<?php

namespace Modules\Admin\Actions\DeviceProfile\Http;

use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Admin\Models\DeviceProfile\DeviceProfileVersion;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\DeviceProfile\DeleteDeviceProfileVersionRequest;
use Modules\Admin\Services\DeviceProfile\DeviceProfilePersistenceService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class DeleteDeviceProfileVersionAction
{
    public function __invoke(
        DeleteDeviceProfileVersionRequest $request,
        Tenant $tenant,
        Project $project,
        DeviceProfile $deviceProfile,
        DeviceProfileVersion $version,
        DeviceProfilePersistenceService $deviceProfilePersistenceService,
        Responder $responder,
    ): Response {
        $deviceProfilePersistenceService->deleteVersion($version);

        return $responder->noContent();
    }
}
