<?php

namespace Modules\Admin\Resources\Webhook;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Admin\Models\Webhook\WebhookDelivery;
use Modules\Admin\Models\Webhook\WebhookDeliveryAttempt;

/** @mixin WebhookDelivery */
final class WebhookDeliveryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WebhookDelivery $delivery */
        $delivery = $this->resource;

        return [
            'id' => (string) $delivery->getKey(),
            'webhook_endpoint_id' => $delivery->webhook_endpoint_id,
            'outbox_event_id' => $delivery->outbox_event_id,
            'event_type' => $this->whenLoaded('outboxEvent', static fn () => $delivery->outboxEvent?->event_type),
            'status' => $delivery->status->value,
            'attempts' => $delivery->attempts,
            'next_attempt_at' => $delivery->next_attempt_at->toISOString(),
            'last_status_code' => $delivery->last_status_code,
            'last_error' => $delivery->last_error,
            'delivered_at' => $delivery->delivered_at?->toISOString(),
            'attempt_history' => $this->whenLoaded('attemptHistory', static fn () => $delivery->attemptHistory->map(
                static fn (WebhookDeliveryAttempt $attempt): array => [
                    'attempt_no' => $attempt->attempt_no,
                    'started_at' => $attempt->started_at->toISOString(),
                    'finished_at' => $attempt->finished_at->toISOString(),
                    'status_code' => $attempt->status_code,
                    'error' => $attempt->error,
                    'duration_ms' => $attempt->duration_ms,
                ],
            )->values()->all()),
            'created_at' => $delivery->created_at?->toISOString(),
            'updated_at' => $delivery->updated_at?->toISOString(),
        ];
    }
}
