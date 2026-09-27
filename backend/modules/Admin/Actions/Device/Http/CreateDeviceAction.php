<?php

namespace Modules\Admin\Actions\Device\Http;

use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Device\StoreDeviceRequest;
use Modules\Admin\Resources\Device\DeviceResource;
use Modules\Admin\Services\Device\DevicePersistenceService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class CreateDeviceAction
{
    public function __invoke(
        StoreDeviceRequest $request,
        Tenant $tenant,
        Project $project,
        DevicePersistenceService $devicePersistenceService,
        Responder $responder,
    ): Response {
        $deviceInput = $request->deviceInput();
        $device = $devicePersistenceService->create(
            $project,
            $deviceInput['attributes'],
            $deviceInput['access_token'],
        );

        return $responder->resource(DeviceResource::make($device), Response::HTTP_CREATED);
    }
}
