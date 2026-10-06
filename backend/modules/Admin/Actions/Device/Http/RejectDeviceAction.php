<?php

namespace Modules\Admin\Actions\Device\Http;

use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Device\RejectDeviceRequest;
use Modules\Admin\Resources\Device\DeviceResource;
use Modules\Admin\Services\DeviceProvisioning\DeviceApprovalService;
use Modules\Admin\Services\DeviceProvisioning\DeviceProvisioningException;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class RejectDeviceAction
{
    public function __invoke(
        RejectDeviceRequest $request,
        Tenant $tenant,
        Project $project,
        Device $device,
        DeviceApprovalService $service,
        Responder $responder,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        try {
            $rejected = $service->reject(
                $project,
                $device,
                (string) $user->getKey(),
                $request->rejectionReason(),
            );
        } catch (DeviceProvisioningException $exception) {
            return $responder->json(['code' => $exception->getMessage()], $exception->status);
        }

        return $responder->resource(DeviceResource::make($rejected));
    }
}
