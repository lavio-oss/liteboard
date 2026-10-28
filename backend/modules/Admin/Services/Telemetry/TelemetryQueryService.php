<?php

namespace Modules\Admin\Services\Telemetry;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Modules\Admin\Enums\Telemetry\TelemetryInterval;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Telemetry\TelemetryIngestOutcome;
use Modules\Admin\Models\Telemetry\TelemetryLatestValue;
use Modules\Admin\Models\Telemetry\TelemetrySample;
use Modules\Admin\Models\Telemetry\TelemetryUnresolvedMessage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TelemetryQueryService
{
    // Bound as strings so microsecond precision and UTC survive on every driver.
    private const string TIMESTAMP_BINDING = 'Y-m-d H:i:s.uP';

    /** @return Collection<int, TelemetryLatestValue> */
    public function latest(Device $device): Collection
    {
        return $device->latestValues()->orderBy('metric')->get();
    }

    /** @return Collection<int, TelemetryUnresolvedMessage> */
    public function recentUnresolvedMessages(int $limit): Collection
    {
        return TelemetryUnresolvedMessage::query()
            ->orderByDesc('received_at')
            ->orderByDesc('processing_id')
            ->limit($limit)
            ->get();
    }

    /**
     * Keyset page over [from, to) ordered by (occurred_at, id).
     *
     * @param  list<string>  $metrics
     * @param  array{occurred_at: CarbonImmutable, id: string}|null  $cursor
     * @return array{samples: Collection<int, TelemetrySample>, next_cursor: string|null}
     */
    public function history(
        Device $device,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $metrics,
        int $limit,
        ?array $cursor,
    ): array {
        $samples = $device->telemetrySamples()
            ->where('occurred_at', '>=', $from->format(self::TIMESTAMP_BINDING))
            ->where('occurred_at', '<', $to->format(self::TIMESTAMP_BINDING))
            ->when($cursor !== null, static function (Builder $query) use ($cursor): void {
                /** @var array{occurred_at: CarbonImmutable, id: string} $cursor */
                $at = $cursor['occurred_at']->format(self::TIMESTAMP_BINDING);
                $query->where(static function (Builder $query) use ($at, $cursor): void {
                    $query->where('occurred_at', '>', $at)
                        ->orWhere(static function (Builder $query) use ($at, $cursor): void {
                            $query->where('occurred_at', '=', $at)->where('id', '>', $cursor['id']);
                        });
                });
            })
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $nextCursor = null;
        if ($samples->count() > $limit) {
            $samples = $samples->take($limit);
            /** @var TelemetrySample $last */
            $last = $samples->last();
            $nextCursor = base64_encode($last->occurred_at->utc()->format('Y-m-d\TH:i:s.uP').'|'.$last->id);
        }

        if ($metrics !== []) {
            $samples->each(static function (TelemetrySample $sample) use ($metrics): void {
                $sample->setAttribute('values', array_intersect_key($sample->values, array_flip($metrics)));
            });
        }

        return ['samples' => $samples->values(), 'next_cursor' => $nextCursor];
    }

    /**
     * Numeric metrics bucketed with date_bin over [from, to); buckets are anchored at $from.
     *
     * @param  list<string>  $metrics
     * @return list<array{bucket_start: string, metric: string, avg: float, min: float, max: float, count: int}>
     */
    public function aggregates(
        Device $device,
        CarbonImmutable $from,
        CarbonImmutable $to,
        TelemetryInterval $interval,
        array $metrics,
    ): array {
        if (DB::getDriverName() !== 'pgsql') {
            throw new LogicException('Telemetry aggregation requires PostgreSQL.');
        }

        $metricFilter = $metrics === [] ? '' : 'AND kv.key IN ('.implode(',', array_fill(0, count($metrics), '?')).')';

        /** @var list<object{bucket_start: string, metric: string, avg: float|string, min: float|string, max: float|string, count: int}> $rows */
        $rows = DB::select(
            <<<SQL
            SELECT date_bin(?::interval, s.occurred_at, ?::timestamptz) AS bucket_start,
                   kv.key AS metric,
                   avg(kv.value::double precision) AS avg,
                   min(kv.value::double precision) AS min,
                   max(kv.value::double precision) AS max,
                   count(*) AS count
            FROM telemetry_samples s
            CROSS JOIN LATERAL jsonb_each(s."values") AS kv(key, value)
            WHERE s.project_id = ?::uuid
              AND s.device_id = ?::uuid
              AND s.occurred_at >= ?::timestamptz
              AND s.occurred_at < ?::timestamptz
              AND jsonb_typeof(kv.value) = 'number'
              $metricFilter
            GROUP BY bucket_start, kv.key
            ORDER BY bucket_start, kv.key
            SQL,
            [
                $interval->seconds().' seconds',
                $from->format(self::TIMESTAMP_BINDING),
                $device->getAttribute('project_id'),
                $device->getKey(),
                $from->format(self::TIMESTAMP_BINDING),
                $to->format(self::TIMESTAMP_BINDING),
                ...$metrics,
            ],
        );

        return array_map(static fn (object $row): array => [
            'bucket_start' => CarbonImmutable::parse($row->bucket_start)->toISOString(),
            'metric' => $row->metric,
            'avg' => (float) $row->avg,
            'min' => (float) $row->min,
            'max' => (float) $row->max,
            'count' => (int) $row->count,
        ], $rows);
    }

    /**
     * A gateway's messages are its sub-devices' messages, so it reads their outcomes alongside its own.
     *
     * @return Builder<TelemetryIngestOutcome>
     */
    private function outcomesVisibleFrom(Device $device): Builder
    {
        return TelemetryIngestOutcome::query()
            ->where('project_id', $device->getAttribute('project_id'))
            ->whereIn('device_id', Device::query()
                ->where('project_id', $device->getAttribute('project_id'))
                ->where(static function (Builder $query) use ($device): void {
                    $query->whereKey($device->getKey())->orWhere('gateway_device_id', $device->getKey());
                })
                ->select('id'));
    }

    /** @return Collection<int, TelemetryIngestOutcome> */
    public function recentOutcomes(Device $device, int $limit): Collection
    {
        return $this->outcomesVisibleFrom($device)
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Resolves a device-scoped message by message_id or processing_id and returns every outcome for it.
     *
     * @return array{outcomes: Collection<int, TelemetryIngestOutcome>, sample: TelemetrySample|null}
     */
    public function trace(Device $device, string $message): array
    {
        $outcomes = $this->outcomesVisibleFrom($device)
            ->where(static function (Builder $query) use ($message): void {
                $query->where('message_id', $message);
                if (Str::isUuid($message)) {
                    $query->orWhere('processing_id', $message);
                }
            })
            ->get();

        if ($outcomes->isEmpty()) {
            throw new NotFoundHttpException;
        }

        // message_id is only unique per device, so the rest of the trace stays on the device that owns the first match.
        $ownerId = $outcomes->firstOrFail()->getAttribute('device_id');
        $messageId = $outcomes->where('device_id', $ownerId)->pluck('message_id')->filter()->first();

        if (is_string($messageId)) {
            $outcomes = $this->outcomesVisibleFrom($device)
                ->where('device_id', $ownerId)
                ->where('message_id', $messageId)
                ->get();
        }

        return [
            'outcomes' => $outcomes->sortBy([['received_at', 'asc'], ['id', 'asc']])->values(),
            'sample' => is_string($messageId)
                ? TelemetrySample::query()
                    ->where('project_id', $device->getAttribute('project_id'))
                    ->where('device_id', $ownerId)
                    ->where('message_id', $messageId)
                    ->first()
                : null,
        ];
    }
}
