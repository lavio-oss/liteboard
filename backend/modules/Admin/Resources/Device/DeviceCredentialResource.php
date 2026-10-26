<?php

namespace Modules\Admin\Resources\Device;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Admin\Enums\Device\DeviceCredentialStatus;
use Modules\Admin\Enums\Device\DeviceCredentialType;
use Modules\Admin\Models\Device\DeviceCredential;
use Modules\Admin\Services\Device\DeviceCredentialService;
use UnexpectedValueException;

/** @mixin DeviceCredential */
final class DeviceCredentialResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DeviceCredential $credential */
        $credential = $this->resource;
        $device = $credential->relationLoaded('device')
            ? $credential->device
            : $credential->device()->firstOrFail();

        if ($device === null) {
            throw new UnexpectedValueException('The credential must belong to a Device.');
        }

        $credentialType = $credential->getAttribute('credential_type');
        $status = $credential->getAttribute('status');
        $rotatedAt = $credential->getAttribute('rotated_at');
        $revokedAt = $credential->getAttribute('revoked_at');
        $updatedAt = $credential->getAttribute('updated_at');

        if (! $credentialType instanceof DeviceCredentialType) {
            throw new UnexpectedValueException('The credential_type attribute must be a DeviceCredentialType.');
        }

        if (! $status instanceof DeviceCredentialStatus) {
            throw new UnexpectedValueException('The status attribute must be a DeviceCredentialStatus.');
        }

        $mqttHost = config('device_provisioning.mqtt_host');

        if (! is_string($mqttHost)) {
            abort(500, 'DEVICE_PROVISIONING_MQTT_HOST is required.');
        }

        $mqttTopics = app(DeviceCredentialService::class)->mqttTopicsFor($device);

        return [
            'device_id' => (string) $device->getKey(),
            'project_id' => (string) $device->getAttribute('project_id'),
            'credential_type' => $credentialType->value,
            'access_token' => (string) $credential->getAttribute('encrypted_token'),
            'status' => $status->value,
            'mqtt' => [
                'host' => $mqttHost,
                'endpoints' => app(DeviceCredentialService::class)->mqttEndpoints($device),
                'topics' => $mqttTopics,
            ],
            'mqtt_topics' => $mqttTopics,
            'rotated_at' => $rotatedAt instanceof CarbonInterface ? $rotatedAt->toISOString() : null,
            'revoked_at' => $revokedAt instanceof CarbonInterface ? $revokedAt->toISOString() : null,
            'updated_at' => $updatedAt instanceof CarbonInterface ? $updatedAt->toISOString() : null,
        ];
    }
}
