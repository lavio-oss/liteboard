<?php

namespace Modules\Admin\Actions\DeviceProfile\Http;

use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\DeviceProfile\ShowDeviceProfileRequest;
use Modules\Admin\Resources\DeviceProfile\DeviceProfileResource;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ShowDeviceProfileAction
{
    public function __invoke(
        ShowDeviceProfileRequest $request,
        Tenant $tenant,
        Project $project,
        DeviceProfile $deviceProfile,
        Responder $responder,
    ): Response {
        $deviceProfile->load([
            'versions' => static fn ($query) => $query->orderBy('version'),
        ]);

        return $responder->resource(DeviceProfileResource::make($deviceProfile));
    }
}
