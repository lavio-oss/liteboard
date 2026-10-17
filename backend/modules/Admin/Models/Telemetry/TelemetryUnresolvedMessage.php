<?php

namespace Modules\Admin\Models\Telemetry;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Central record of telemetry whose topic scope the database could not confirm.
 * Topic IDs are unverified strings and must never be used to reach tenant data.
 *
 * @property string $processing_id
 * @property string $topic
 * @property string $reason
 * @property int $payload_size
 * @property list<array{stage: string, at: string}> $stages
 * @property CarbonImmutable $received_at
 */
final class TelemetryUnresolvedMessage extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'processing_id';

    protected $keyType = 'string';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload_size' => 'integer',
            'stages' => 'array',
            'received_at' => 'immutable_datetime',
        ];
    }
}
