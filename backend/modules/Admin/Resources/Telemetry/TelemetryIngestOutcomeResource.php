<?php

namespace Modules\Admin\Resources\Telemetry;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Admin\Models\Telemetry\TelemetryIngestOutcome;

/** @mixin TelemetryIngestOutcome */
final class TelemetryIngestOutcomeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TelemetryIngestOutcome $outcome */
        $outcome = $this->resource;

        return [
            'id' => $outcome->id,
            'project_id' => $outcome->project_id,
            'device_id' => $outcome->device_id,
            'processing_id' => $outcome->processing_id,
            'message_id' => $outcome->message_id,
            'status' => $outcome->status,
            'reason_codes' => $outcome->reason_codes,
            'stages' => $outcome->stages,
            'received_at' => $outcome->received_at->toISOString(),
        ];
    }
}
