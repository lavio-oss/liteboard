<?php

namespace Modules\Admin\Actions\Device\Http;

use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Device\ShowDeviceRequest;
use Modules\Admin\Resources\Device\DeviceResource;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ShowDeviceAction
{
    public function __invoke(
        Device $device,
        Responder $responder,
    ): Response {
        $device->load('credential');

        return $responder->resource(DeviceResource::make($device));
    }
}
