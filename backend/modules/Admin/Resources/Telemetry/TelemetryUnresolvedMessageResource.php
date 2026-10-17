<?php

namespace Modules\Admin\Resources\Telemetry;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Admin\Models\Telemetry\TelemetryUnresolvedMessage;

/** @mixin TelemetryUnresolvedMessage */
final class TelemetryUnresolvedMessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TelemetryUnresolvedMessage $message */
        $message = $this->resource;

        return [
            'processing_id' => $message->processing_id,
            'topic' => $message->topic,
            'reason' => $message->reason,
            'payload_size' => $message->payload_size,
            'stages' => $message->stages,
            'received_at' => $message->received_at->toISOString(),
        ];
    }
}
