<?php

namespace Modules\Admin\Actions\Device\Http;

use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Device\ShowDeviceCredentialRequest;
use Modules\Admin\Resources\Device\DeviceCredentialResource;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ShowDeviceCredentialAction
{
    public function __invoke(
        ShowDeviceCredentialRequest $request,
        Tenant $tenant,
        Project $project,
        Device $device,
        Responder $responder,
    ): Response {
        $credential = $device->credential()->firstOrFail();
        $credential->setRelation('device', $device);

        return $responder->resource(DeviceCredentialResource::make($credential));
    }
}
