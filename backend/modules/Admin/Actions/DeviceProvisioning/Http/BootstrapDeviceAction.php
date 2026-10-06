<?php

namespace Modules\Admin\Actions\DeviceProvisioning\Http;

use Illuminate\Http\JsonResponse;
use Modules\Admin\Enums\Device\DeviceApprovalStatus;
use Modules\Admin\Requests\DeviceProvisioning\BootstrapDeviceRequest;
use Modules\Admin\Services\Device\DeviceCredentialService;
use Modules\Admin\Services\DeviceProvisioning\DeviceProvisioningException;
use Modules\Admin\Services\DeviceProvisioning\DeviceProvisioningService;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

final class BootstrapDeviceAction
{
    public function __invoke(
        BootstrapDeviceRequest $request,
        DeviceProvisioningService $service,
        DeviceCredentialService $credentialService,
        Responder $responder,
    ): Response {
        $allowInsecureHttp = config('app.env') === 'local'
            && config('device_provisioning.allow_insecure_http') === true;

        if (! $request->isSecure() && ! $allowInsecureHttp) {
            return $responder->json(['code' => 'HTTPS_REQUIRED'], Response::HTTP_UPGRADE_REQUIRED)
                ->header('Cache-Control', 'no-store');
        }

        if (! is_string(config('device_provisioning.mqtt_host'))) {
            abort(Response::HTTP_INTERNAL_SERVER_ERROR, 'DEVICE_PROVISIONING_MQTT_HOST is required.');
        }

        try {
            $input = $request->bootstrapInput();
            $result = $service->bootstrap(
                $input['key'],
                $input['secret'],
                $input['request_id'],
                $input['input'],
            );
        } catch (DeviceProvisioningException $exception) {
            $response = $responder->json(['code' => $exception->getMessage()], $exception->status)
                ->header('Cache-Control', 'no-store');

            if ($exception->retryAfter !== null) {
                $response->headers->set('Retry-After', (string) $exception->retryAfter);
            }

            return $response;
        }

        $device = $result['device'];
        $projectId = (string) $device->getAttribute('project_id');
        $approvalStatus = $device->getAttribute('approval_status');

        if (! $approvalStatus instanceof DeviceApprovalStatus) {
            throw new UnexpectedValueException('The approval_status attribute must be a DeviceApprovalStatus.');
        }

        return new JsonResponse([
            'status' => 'success',
            'credential_type' => 'access_token',
            'device' => [
                'id' => (string) $device->getKey(),
                'project_id' => $projectId,
                'name' => (string) $device->name,
                'code' => (string) $device->code,
            ],
            'provisioning' => [
                'request_id' => $result['request_id'],
                'approval_status' => $approvalStatus->value,
            ],
            'credentials' => ['access_token' => $result['access_token']],
            'mqtt' => [
                'host' => config('device_provisioning.mqtt_host'),
                'port' => 8883,
                'tls' => true,
                'topics' => $credentialService->mqttTopicsFor($device),
            ],
        ], $result['created'] ? Response::HTTP_CREATED : Response::HTTP_OK, [
            'Cache-Control' => 'no-store',
        ]);
    }
}
