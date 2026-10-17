<?php

namespace Modules\Admin\Models\Telemetry;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Admin\Models\Device\Device;

/**
 * Written only by the telemetry data plane; the control plane reads it.
 *
 * @property string $id
 * @property string $project_id
 * @property string $device_id
 * @property string|null $device_profile_version_id
 * @property string $message_id
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $received_at
 * @property array<string, mixed> $values
 */
final class TelemetrySample extends Model
{
    use HasUuids;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'values' => 'array',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
