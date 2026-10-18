<?php

namespace Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Admin\Enums\Webhook\WebhookDeliveryStatus;
use Modules\Admin\Models\Alert\OutboxEvent;
use Modules\Admin\Models\Webhook\WebhookDelivery;
use Modules\Admin\Models\Webhook\WebhookEndpoint;

/** @extends Factory<WebhookDelivery> */
final class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    /** @return array{status: string, attempts: int, next_attempt_at: CarbonImmutable} */
    public function definition(): array
    {
        return [
            'status' => WebhookDeliveryStatus::Pending->value,
            'attempts' => 0,
            'next_attempt_at' => now()->toImmutable(),
        ];
    }

    public function forEvent(OutboxEvent $event, WebhookEndpoint $endpoint): static
    {
        return $this->state([
            'project_id' => (string) $endpoint->getAttribute('project_id'),
            'outbox_event_id' => (string) $event->getKey(),
            'webhook_endpoint_id' => (string) $endpoint->getKey(),
        ]);
    }
}
