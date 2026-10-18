<?php

namespace Modules\Admin\Models\Webhook;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $webhook_delivery_id
 * @property int $attempt_no
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $finished_at
 * @property int|null $status_code
 * @property string|null $error
 * @property int $duration_ms
 */
final class WebhookDeliveryAttempt extends Model
{
    use HasUuids;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempt_no' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'status_code' => 'integer',
            'duration_ms' => 'integer',
        ];
    }
}
