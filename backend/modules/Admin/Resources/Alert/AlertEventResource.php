<?php

namespace Modules\Admin\Resources\Alert;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Admin\Models\Alert\AlertEvent;

/** @mixin AlertEvent */
final class AlertEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AlertEvent $event */
        $event = $this->resource;

        return [
            'id' => (string) $event->getKey(),
            'type' => $event->type->value,
            'occurred_at' => $event->occurred_at->toISOString(),
            'actor_id' => $event->actor_id,
            'alert_rule_revision_id' => $event->alert_rule_revision_id,
            'sample_id' => $event->sample_id,
            'message_id' => $event->message_id,
            'data' => $event->data,
        ];
    }
}
