<?php

namespace Modules\Admin\Actions\Device\Http;

use Modules\Admin\Enums\Device\DeviceApprovalStatus;
use Modules\Admin\Enums\Device\DeviceStatus;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Requests\Device\RegisterGatewaySubDeviceRequest;
use Modules\Admin\Services\Device\DevicePersistenceService;
use Modules\Core\Services\Tenant\TenantContext;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class RegisterGatewaySubDeviceAction
{
    public function __invoke(
        RegisterGatewaySubDeviceRequest $request,
        Device $gateway,
        DevicePersistenceService $devicePersistenceService,
        TenantContext $tenantContext,
        Responder $responder,
    ): Response {
        $project = $gateway->project()->firstOrFail();
        $tenant = $project->tenant()->firstOrFail();

        if (
            $gateway->getAttribute('is_gateway') !== true
            || $gateway->getAttribute('status') !== DeviceStatus::Active
            || $gateway->getAttribute('approval_status') !== DeviceApprovalStatus::Approved
            || $project->getAttribute('status') !== 'active'
            || $tenant->getAttribute('status') !== 'active'
        ) {
            return $responder->json(['code' => 'GATEWAY_UNAVAILABLE'], Response::HTTP_CONFLICT);
        }

        $tenantContext->set($tenant);

        try {
            $device = $devicePersistenceService->findOrCreateSubDevice($gateway, $request->subDeviceCode());
        } catch (ConflictHttpException $exception) {
            return $responder->json(['code' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }

        return $responder->json(['id' => (string) $device->getKey()]);
    }
}
