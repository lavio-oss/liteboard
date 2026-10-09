<?php

namespace Modules\Admin\Actions\DeviceProfile\Http;

use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\DeviceProfile\StoreDeviceProfileVersionRequest;
use Modules\Admin\Resources\DeviceProfile\DeviceProfileVersionResource;
use Modules\Admin\Services\DeviceProfile\DeviceProfilePersistenceService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class CreateDeviceProfileVersionAction
{
    public function __invoke(
        StoreDeviceProfileVersionRequest $request,
        Tenant $tenant,
        Project $project,
        DeviceProfile $deviceProfile,
        DeviceProfilePersistenceService $deviceProfilePersistenceService,
        Responder $responder,
    ): Response {
        $input = $request->versionInput();
        $version = $deviceProfilePersistenceService->createVersion(
            $deviceProfile,
            $input['schema_code'],
            $input['definition'],
            $input['compatibility_note'] ?? null,
        );

        return $responder->resource(
            DeviceProfileVersionResource::make($version),
            Response::HTTP_CREATED,
        );
    }
}
