<?php

namespace Modules\Admin\Models\Alert;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Admin\Enums\Alert\AlertEventType;

/**
 * @property string $project_id
 * @property string $alert_id
 * @property AlertEventType $type
 * @property CarbonImmutable $occurred_at
 * @property string|null $actor_id
 * @property string $alert_rule_revision_id
 * @property string|null $sample_id
 * @property string|null $message_id
 * @property array<string, mixed> $data
 */
final class AlertEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => AlertEventType::class,
            'occurred_at' => 'immutable_datetime',
            'data' => 'array',
        ];
    }

    /** @return BelongsTo<Alert, $this> */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }
}
