<?php

namespace Modules\Admin\Resources\DeviceProfile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Admin\Models\DeviceProfile\DeviceProfile;

/** @mixin DeviceProfile */
final class DeviceProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DeviceProfile $profile */
        $profile = $this->resource;

        return [
            'id' => (string) $profile->getKey(),
            'project_id' => (string) $profile->getAttribute('project_id'),
            'code' => $profile->code,
            'name' => $profile->name,
            'description' => $profile->description,
            'versions' => DeviceProfileVersionResource::collection($profile->versions),
            'created_at' => $profile->created_at?->toISOString(),
            'updated_at' => $profile->updated_at?->toISOString(),
        ];
    }
}
