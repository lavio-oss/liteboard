<?php

namespace Modules\Admin\Actions\Device\Http;

use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Device\RotateDeviceCredentialRequest;
use Modules\Admin\Resources\Device\DeviceCredentialResource;
use Modules\Admin\Services\Device\DeviceCredentialService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class RotateDeviceCredentialAction
{
    public function __invoke(
        RotateDeviceCredentialRequest $request,
        Tenant $tenant,
        Project $project,
        Device $device,
        DeviceCredentialService $deviceCredentialService,
        Responder $responder,
    ): Response {
        $credential = $deviceCredentialService->rotateCredential($device);
        $credential->setRelation('device', $device);

        return $responder->resource(DeviceCredentialResource::make($credential));
    }
}
