<?php

namespace Modules\Admin\Actions\DeviceProvisioning\Http;

use Modules\Admin\Requests\DeviceProvisioning\DeviceApprovalStatusRequest;
use Modules\Admin\Services\DeviceProvisioning\DeviceProvisioningException;
use Modules\Admin\Services\DeviceProvisioning\DeviceProvisioningService;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ReadDeviceApprovalStatusAction
{
    public function __invoke(
        DeviceApprovalStatusRequest $request,
        DeviceProvisioningService $service,
        Responder $responder,
    ): Response {
        $allowInsecureHttp = config('app.env') === 'local'
            && config('device_provisioning.allow_insecure_http') === true;

        if (! $request->isSecure() && ! $allowInsecureHttp) {
            return $responder->json(['code' => 'HTTPS_REQUIRED'], Response::HTTP_UPGRADE_REQUIRED)
                ->header('Cache-Control', 'no-store');
        }

        $input = $request->statusInput();

        try {
            $status = $service->readApprovalStatus($input['key'], $input['secret'], $input['request_id']);
        } catch (DeviceProvisioningException $exception) {
            $response = $responder->json(['code' => $exception->getMessage()], $exception->status)
                ->header('Cache-Control', 'no-store');

            if ($exception->retryAfter !== null) {
                $response->headers->set('Retry-After', (string) $exception->retryAfter);
            }

            return $response;
        }

        return $responder->json([
            'approval_status' => $status['approval_status']->value,
            'rejection_reason' => $status['rejection_reason'],
        ])->header('Cache-Control', 'no-store');
    }
}
