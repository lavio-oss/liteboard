<?php

namespace Modules\Admin\Resources\Webhook;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Admin\Models\Webhook\WebhookEndpoint;

/**
 * Never includes the secret; create and rotate return it once beside `data`.
 *
 * @mixin WebhookEndpoint
 */
final class WebhookEndpointResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WebhookEndpoint $endpoint */
        $endpoint = $this->resource;

        return [
            'id' => (string) $endpoint->getKey(),
            'project_id' => $endpoint->project_id,
            'name' => $endpoint->name,
            'url' => $endpoint->url,
            'event_types' => $endpoint->event_types,
            'alert_rule_ids' => $endpoint->alert_rule_ids,
            'enabled' => $endpoint->enabled,
            'secret_rotated_at' => $endpoint->secret_rotated_at?->toISOString(),
            'created_at' => $endpoint->created_at?->toISOString(),
            'updated_at' => $endpoint->updated_at?->toISOString(),
        ];
    }
}
