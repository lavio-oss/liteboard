<?php

namespace Modules\Admin\Resources\DeviceProfile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Admin\Models\DeviceProfile\DeviceProfileVersion;

/** @mixin DeviceProfileVersion */
final class DeviceProfileVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DeviceProfileVersion $version */
        $version = $this->resource;

        return [
            'id' => (string) $version->getKey(),
            'project_id' => (string) $version->getAttribute('project_id'),
            'device_profile_id' => (string) $version->getAttribute('device_profile_id'),
            'version' => $version->version,
            'schema_code' => $version->schema_code,
            'definition' => $version->definition,
            'compatibility_note' => $version->compatibility_note,
            'created_at' => $version->created_at?->toISOString(),
            'updated_at' => $version->updated_at?->toISOString(),
        ];
    }
}
