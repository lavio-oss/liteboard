<?php

namespace Modules\Admin\Models\Webhook;

use Carbon\CarbonImmutable;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory as EloquentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Admin\Enums\Webhook\WebhookDeliveryStatus;
use Modules\Admin\Models\Alert\OutboxEvent;

/**
 * @property string $project_id
 * @property string $outbox_event_id
 * @property string $webhook_endpoint_id
 * @property WebhookDeliveryStatus $status
 * @property int $attempts
 * @property CarbonImmutable $next_attempt_at
 * @property CarbonImmutable|null $lease_expires_at
 * @property int|null $last_status_code
 * @property string|null $last_error
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory, HasUuids;

    /** @return EloquentFactory<WebhookDelivery> */
    protected static function newFactory(): EloquentFactory
    {
        return WebhookDeliveryFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => WebhookDeliveryStatus::class,
            'attempts' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'last_status_code' => 'integer',
            'delivered_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<WebhookEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    /** @return BelongsTo<OutboxEvent, $this> */
    public function outboxEvent(): BelongsTo
    {
        return $this->belongsTo(OutboxEvent::class);
    }

    /** @return HasMany<WebhookDeliveryAttempt, $this> */
    public function attemptHistory(): HasMany
    {
        return $this->hasMany(WebhookDeliveryAttempt::class);
    }
}
