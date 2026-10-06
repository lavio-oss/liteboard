<?php

namespace Modules\Admin\Resources\DeviceProvisioning;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Admin\Models\DeviceProvisioning\ProjectProvisioningProfile;

/** @mixin ProjectProvisioningProfile */
final class ProjectProvisioningProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ProjectProvisioningProfile $profile */
        $profile = $this->resource;
        $secretRotatedAt = $profile->getAttribute('secret_rotated_at');
        $createdAt = $profile->getAttribute('created_at');
        $updatedAt = $profile->getAttribute('updated_at');

        return [
            'id' => (string) $profile->getKey(),
            'project_id' => (string) $profile->getAttribute('project_id'),
            'enabled' => $profile->enabled,
            'provision_key' => $profile->provision_key,
            'secret_rotated_at' => $secretRotatedAt instanceof CarbonInterface ? $secretRotatedAt->toISOString() : null,
            'created_at' => $createdAt instanceof CarbonInterface ? $createdAt->toISOString() : null,
            'updated_at' => $updatedAt instanceof CarbonInterface ? $updatedAt->toISOString() : null,
        ];
    }
}
