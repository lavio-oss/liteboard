<?php

namespace Modules\Admin\Resources\Telemetry;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Admin\Models\Telemetry\TelemetrySample;

/** @mixin TelemetrySample */
final class TelemetrySampleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TelemetrySample $sample */
        $sample = $this->resource;

        return [
            'id' => $sample->id,
            'project_id' => $sample->project_id,
            'device_id' => $sample->device_id,
            'device_profile_version_id' => $sample->device_profile_version_id,
            'message_id' => $sample->message_id,
            'occurred_at' => $sample->occurred_at->toISOString(),
            'received_at' => $sample->received_at->toISOString(),
            'values' => (object) $sample->values,
        ];
    }
}
