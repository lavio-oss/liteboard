<?php

namespace Modules\Admin\Models\Telemetry;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Admin\Models\Device\Device;

/**
 * Written only by the telemetry data plane (one row per device metric); the control plane reads it.
 *
 * @property string $project_id
 * @property string $device_id
 * @property string $metric
 * @property int|float|string|bool $value
 * @property string $sample_id
 * @property string $message_id
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $received_at
 */
final class TelemetryLatestValue extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    // The real key is (device_id, metric); the model is read-only so Eloquent never writes by key.
    protected $primaryKey = 'metric';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value' => 'json',
            'occurred_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
