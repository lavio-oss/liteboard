<?php

namespace Modules\Admin\Services\DeviceProvisioning;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Enums\Device\DeviceApprovalStatus;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;

final class DeviceApprovalService
{
    public function approve(Project $project, Device $device, string $actorId): Device
    {
        return $this->decide($project, $device, $actorId, DeviceApprovalStatus::Approved, null);
    }

    public function reject(Project $project, Device $device, string $actorId, string $reason): Device
    {
        return $this->decide($project, $device, $actorId, DeviceApprovalStatus::Rejected, $reason);
    }

    private function decide(
        Project $project,
        Device $device,
        string $actorId,
        DeviceApprovalStatus $decision,
        ?string $reason,
    ): Device {
        return DB::transaction(function () use ($project, $device, $actorId, $decision, $reason): Device {
            $lockedProject = Project::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
            $lockedDevice = $lockedProject->devices()
                ->whereKey($device->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedDevice->getAttribute('approval_status') !== DeviceApprovalStatus::Pending) {
                throw new DeviceProvisioningException(409, 'DEVICE_APPROVAL_ALREADY_DECIDED');
            }

            $lockedDevice->forceFill(['approval_status' => $decision->value])->save();
            $lockedProject->provisioningAudits()->create([
                'device_id' => $lockedDevice->getKey(),
                'actor_id' => $actorId,
                'action' => $decision === DeviceApprovalStatus::Approved
                    ? 'device_approved'
                    : 'device_rejected',
                'reason' => $reason,
            ]);

            return $lockedDevice->refresh()->load('credential');
        });
    }
}
