<?php

namespace Modules\Admin\Resources\Alert;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Admin\Models\Alert\Alert;

/** @mixin Alert */
final class AlertResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Alert $alert */
        $alert = $this->resource;

        return [
            'id' => (string) $alert->getKey(),
            'project_id' => $alert->project_id,
            'device_id' => $alert->device_id,
            'equipment_asset_id' => $alert->equipment_asset_id,
            'alert_rule_id' => $alert->alert_rule_id,
            'alert_rule_revision_id' => $alert->alert_rule_revision_id,
            'device_profile_version_id' => $alert->device_profile_version_id,
            'severity' => $alert->severity->value,
            'opened_at' => $alert->opened_at->toISOString(),
            'last_seen_at' => $alert->last_seen_at->toISOString(),
            'cleared_at' => $alert->cleared_at?->toISOString(),
            'clear_reason' => $alert->clear_reason?->value,
            'acknowledged_at' => $alert->acknowledged_at?->toISOString(),
            'acknowledged_by' => $alert->acknowledged_by,
            'open_sample_id' => $alert->open_sample_id,
            'open_message_id' => $alert->open_message_id,
            'clear_sample_id' => $alert->clear_sample_id,
            'clear_message_id' => $alert->clear_message_id,
            'evidence' => $alert->evidence,
            'events' => AlertEventResource::collection($this->whenLoaded('events')),
        ];
    }
}
