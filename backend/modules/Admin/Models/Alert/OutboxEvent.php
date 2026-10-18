<?php

namespace Modules\Admin\Models\Alert;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Durable integration event; webhooks fan out from it after the producing transaction commits.
 *
 * @property string $project_id
 * @property string $event_type
 * @property string $aggregate_id
 * @property string $idempotency_key
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $fanned_out_at
 */
final class OutboxEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
            'fanned_out_at' => 'immutable_datetime',
        ];
    }
}
