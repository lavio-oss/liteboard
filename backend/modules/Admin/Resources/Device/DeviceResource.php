<?php

namespace Modules\Admin\Resources\Device;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Admin\Enums\Device\DeviceConnectionState;
use Modules\Admin\Enums\Device\DeviceCredentialStatus;
use Modules\Admin\Enums\Device\DeviceCredentialType;
use Modules\Admin\Enums\Device\DeviceStatus;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Services\Device\DeviceCredentialService;
use UnexpectedValueException;

/** @mixin Device */
final class DeviceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Device $device */
        $device = $this->resource;
        $status = $device->getAttribute('status');
        $connectionState = $device->getAttribute('connection_state');
        $lastSeenAt = $device->getAttribute('last_seen_at');
        $createdAt = $device->getAttribute('created_at');
        $updatedAt = $device->getAttribute('updated_at');

        if (! $status instanceof DeviceStatus) {
            throw new UnexpectedValueException('The status attribute must be a DeviceStatus.');
        }

        if (! $connectionState instanceof DeviceConnectionState) {
            throw new UnexpectedValueException('The connection_state attribute must be a DeviceConnectionState.');
        }

        $credential = $device->relationLoaded('credential')
            ? $device->credential
            : $device->credential()->first();

        $credentialType = $credential?->getAttribute('credential_type');
        $credentialStatus = $credential?->getAttribute('status');

        if (! $credentialType instanceof DeviceCredentialType) {
            throw new UnexpectedValueException('The credential_type attribute must be a DeviceCredentialType.');
        }

        if (! $credentialStatus instanceof DeviceCredentialStatus) {
            throw new UnexpectedValueException('The credential status attribute must be a DeviceCredentialStatus.');
        }

        return [
            'id' => (string) $device->getKey(),
            'project_id' => (string) $device->getAttribute('project_id'),
            'equipment_asset_id' => $device->equipment_asset_id === null
                ? null
                : (string) $device->equipment_asset_id,
            'name' => (string) $device->name,
            'code' => (string) $device->code,
            'description' => $device->description,
            'status' => $status->value,
            'connection_state' => $connectionState->value,
            'credential_type' => $credentialType->value,
            'credential_status' => $credentialStatus->value,
            'mqtt_topics' => app(DeviceCredentialService::class)->mqttTopicsFor($device),
            'last_seen_at' => $lastSeenAt instanceof CarbonInterface ? $lastSeenAt->toISOString() : null,
            'created_at' => $createdAt instanceof CarbonInterface ? $createdAt->toISOString() : null,
            'updated_at' => $updatedAt instanceof CarbonInterface ? $updatedAt->toISOString() : null,
        ];
    }
}
