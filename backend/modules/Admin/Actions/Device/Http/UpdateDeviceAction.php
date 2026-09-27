<?php

namespace Modules\Admin\Actions\Device\Http;

use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Device\UpdateDeviceRequest;
use Modules\Admin\Resources\Device\DeviceResource;
use Modules\Admin\Services\Device\DevicePersistenceService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class UpdateDeviceAction
{
    public function __invoke(
        UpdateDeviceRequest $request,
        Tenant $tenant,
        Project $project,
        Device $device,
        DevicePersistenceService $devicePersistenceService,
        Responder $responder,
    ): Response {
        $device = $devicePersistenceService->update(
            $device,
            $request->deviceAttributes(),
        );

        return $responder->resource(DeviceResource::make($device));
    }
}
