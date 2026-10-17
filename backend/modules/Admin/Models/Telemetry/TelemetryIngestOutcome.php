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
 * @property string $processing_id
 * @property string|null $message_id
 * @property string $status
 * @property list<array{code: string, field: string|null}> $reason_codes
 * @property list<array{stage: string, at: string, subscribers?: int}> $stages
 * @property CarbonImmutable $received_at
 */
final class TelemetryIngestOutcome extends Model
{
    use HasUuids;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reason_codes' => 'array',
            'stages' => 'array',
            'received_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
