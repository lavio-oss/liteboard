<?php

namespace Modules\Admin\Resources\Telemetry;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Admin\Models\Telemetry\TelemetryLatestValue;

/** @mixin TelemetryLatestValue */
final class TelemetryLatestValueResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TelemetryLatestValue $latestValue */
        $latestValue = $this->resource;

        return [
            'metric' => $latestValue->metric,
            'value' => $latestValue->value,
            'sample_id' => $latestValue->sample_id,
            'message_id' => $latestValue->message_id,
            'occurred_at' => $latestValue->occurred_at->toISOString(),
            'received_at' => $latestValue->received_at->toISOString(),
        ];
    }
}
