<?php

namespace Modules\Admin\Resources\Device;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Admin\Enums\Device\DeviceApprovalStatus;
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
        $approvalStatus = $device->getAttribute('approval_status');
        $connectionState = $device->getAttribute('connection_state');
        $lastSeenAt = $device->getAttribute('last_seen_at');
        $createdAt = $device->getAttribute('created_at');
        $updatedAt = $device->getAttribute('updated_at');

        if (! $status instanceof DeviceStatus) {
            throw new UnexpectedValueException('The status attribute must be a DeviceStatus.');
        }

        if (! $approvalStatus instanceof DeviceApprovalStatus) {
            throw new UnexpectedValueException('The approval_status attribute must be a DeviceApprovalStatus.');
        }

        $rejectionAudit = $device->relationLoaded('rejectionAudit')
            ? $device->rejectionAudit
            : $device->rejectionAudit()->first();
        $approvalReason = null;

        if ($approvalStatus === DeviceApprovalStatus::Rejected) {
            $reason = $rejectionAudit?->getAttribute('reason');

            if (! is_string($reason)) {
                throw new UnexpectedValueException('A rejected device must have an approval reason.');
            }

            $approvalReason = $reason;
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
            'device_profile_version_id' => $device->getAttribute('device_profile_version_id') === null
                ? null
                : (string) $device->getAttribute('device_profile_version_id'),
            'name' => (string) $device->name,
            'code' => (string) $device->code,
            'description' => $device->description,
            'status' => $status->value,
            'approval_status' => $approvalStatus->value,
            'approval_reason' => $approvalReason,
            'connection_state' => $connectionState->value,
            'allow_plaintext' => (bool) $device->getAttribute('allow_plaintext'),
            'credential_type' => $credentialType->value,
            'credential_status' => $credentialStatus->value,
            'mqtt_topics' => app(DeviceCredentialService::class)->mqttTopicsFor($device),
            'last_seen_at' => $lastSeenAt instanceof CarbonInterface ? $lastSeenAt->toISOString() : null,
            'created_at' => $createdAt instanceof CarbonInterface ? $createdAt->toISOString() : null,
            'updated_at' => $updatedAt instanceof CarbonInterface ? $updatedAt->toISOString() : null,
        ];
    }
}
