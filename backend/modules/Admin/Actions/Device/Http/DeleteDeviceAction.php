<?php

namespace Modules\Admin\Actions\Device\Http;

use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Device\DeleteDeviceRequest;
use Modules\Admin\Services\Device\DevicePersistenceService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class DeleteDeviceAction
{
    public function __invoke(
        DeleteDeviceRequest $request,
        Tenant $tenant,
        Project $project,
        Device $device,
        DevicePersistenceService $devicePersistenceService,
        Responder $responder,
    ): Response {
        $devicePersistenceService->delete($device);

        return $responder->noContent();
    }
}
