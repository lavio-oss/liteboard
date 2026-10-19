<?php

namespace Modules\Admin\Services\Overview;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Enums\AlertRule\AlertSeverity;
use Modules\Admin\Enums\Device\DeviceApprovalStatus;
use Modules\Admin\Enums\Device\DeviceConnectionState;
use Modules\Admin\Enums\Webhook\WebhookDeliveryStatus;
use Modules\Admin\Models\Alert\Alert;
use Modules\Admin\Models\AlertRule\AlertRule;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Models\Webhook\WebhookDelivery;
use Modules\Admin\Models\Webhook\WebhookEndpoint;

/**
 * Project-scoped health summary for the Overview screen. Every query filters on the project, so nothing
 * crosses tenant or project boundaries.
 *
 * @phpstan-type AttentionDevice array{id: string, name: string, code: string, connection_state: string, approval_status: string, last_seen_at: string|null, reason: string}
 * @phpstan-type UnacknowledgedAlert array{id: string, severity: string, rule_name: string, device_name: string, opened_at: string}
 * @phpstan-type IngestBucket array{start: string, persisted: int, rejected: int, other: int}
 * @phpstan-type Pipeline array{
 *     evaluation_latency_p50_ms: float|null,
 *     evaluation_latency_p95_ms: float|null,
 *     pending_evaluation_jobs: int,
 *     oldest_pending_job_age_s: int|null,
 *     outbox_pending: int,
 *     oldest_outbox_age_s: int|null,
 *     deliveries: array<string, int>,
 *     failing_endpoints: list<array{id: string, name: string, failed: int, last_error: string|null}>
 * }
 * @phpstan-type Overview array{
 *     generated_at: string,
 *     window_hours: int,
 *     devices: array{total: int, online: int, offline: int, unstable: int, pending_approval: int, unassigned: int, attention: list<AttentionDevice>},
 *     alerts: array{active: int, active_unacknowledged: int, active_by_severity: array<string, int>, opened_in_window: int, top_rules: list<array{id: string, name: string, opened: int}>, unacknowledged: list<UnacknowledgedAlert>},
 *     ingest: array{totals: array<string, int>, buckets: list<IngestBucket>, top_reasons: list<array{code: string, count: int}>},
 *     pipeline: Pipeline|null
 * }
 */
final class ProjectOverviewService
{
    public const WINDOW_HOURS = 24;

    public const LIST_LIMIT = 10;

    // Reason codes are counted over the newest rejected messages only, which bounds the work per request.
    public const REASON_SAMPLE = 5000;

    private const OUTCOME_STATUSES = ['persisted', 'rejected', 'duplicate', 'idempotency_conflict', 'unresolved', 'skipped'];

    /** @return Overview */
    public function summarize(Project $project, bool $includePipeline, CarbonImmutable $now): array
    {
        $since = $now->subHours(self::WINDOW_HOURS);

        return [
            'generated_at' => $now->toISOString(),
            'window_hours' => self::WINDOW_HOURS,
            'devices' => $this->devices($project),
            'alerts' => $this->alerts($project, $since),
            'ingest' => $this->ingest($project, $since, $now),
            'pipeline' => $includePipeline ? $this->pipeline($project, $since, $now) : null,
        ];
    }

    /** @return array{total: int, online: int, offline: int, unstable: int, pending_approval: int, unassigned: int, attention: list<AttentionDevice>} */
    private function devices(Project $project): array
    {
        $devices = Device::query()->where('project_id', $project->getKey());
        $count = static fn (callable $filter): int => $filter(clone $devices)->count();

        // Pending approval first, then devices that never reported or went quiet longest ago, then unassigned ones.
        $attention = Device::query()
            ->where('project_id', $project->getKey())
            ->where(static function ($query): void {
                $query->where('approval_status', DeviceApprovalStatus::Pending->value)
                    ->orWhere('connection_state', '!=', DeviceConnectionState::Online->value)
                    ->orWhereNull('device_profile_version_id');
            })
            ->orderByRaw('CASE WHEN approval_status = ? THEN 0 WHEN connection_state != ? THEN 1 ELSE 2 END', [
                DeviceApprovalStatus::Pending->value,
                DeviceConnectionState::Online->value,
            ])
            ->orderByRaw('last_seen_at IS NOT NULL')
            ->orderBy('last_seen_at')
            ->orderBy('name')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(static fn (Device $device): array => [
                'id' => (string) $device->getKey(),
                'name' => (string) $device->getAttribute('name'),
                'code' => (string) $device->getAttribute('code'),
                'connection_state' => (string) $device->getAttribute('connection_state')->value,
                'approval_status' => (string) $device->getAttribute('approval_status')->value,
                'last_seen_at' => $device->getAttribute('last_seen_at')?->toISOString(),
                'reason' => match (true) {
                    $device->getAttribute('approval_status') === DeviceApprovalStatus::Pending => 'pending_approval',
                    $device->getAttribute('connection_state') !== DeviceConnectionState::Online => 'not_online',
                    default => 'unassigned',
                },
            ])
            ->values()
            ->all();

        return [
            'total' => $count(static fn ($query) => $query),
            'online' => $count(static fn ($query) => $query->where('connection_state', DeviceConnectionState::Online->value)),
            'offline' => $count(static fn ($query) => $query->where('connection_state', DeviceConnectionState::Offline->value)),
            'unstable' => $count(static fn ($query) => $query->where('connection_state', DeviceConnectionState::Unstable->value)),
            'pending_approval' => $count(static fn ($query) => $query->where('approval_status', DeviceApprovalStatus::Pending->value)),
            'unassigned' => $count(static fn ($query) => $query->whereNull('device_profile_version_id')),
            'attention' => $attention,
        ];
    }

    /** @return array{active: int, active_unacknowledged: int, active_by_severity: array<string, int>, opened_in_window: int, top_rules: list<array{id: string, name: string, opened: int}>, unacknowledged: list<UnacknowledgedAlert>} */
    private function alerts(Project $project, CarbonImmutable $since): array
    {
        $active = Alert::query()->where('project_id', $project->getKey())->whereNull('cleared_at');
        $bySeverity = (clone $active)
            ->select('severity', DB::raw('COUNT(*) AS total'))
            ->groupBy('severity')
            ->pluck('total', 'severity');
        $openedByRule = Alert::query()
            ->where('project_id', $project->getKey())
            ->where('opened_at', '>=', $since)
            ->select('alert_rule_id', DB::raw('COUNT(*) AS total'))
            ->groupBy('alert_rule_id')
            ->orderByDesc('total')
            ->limit(5)
            ->pluck('total', 'alert_rule_id');
        // The alerts an engineer has to look at first: active, not acknowledged, most severe and oldest first.
        $unacknowledged = (clone $active)
            ->whereNull('acknowledged_at')
            ->orderByRaw('CASE severity WHEN ? THEN 0 WHEN ? THEN 1 WHEN ? THEN 2 ELSE 3 END', [
                AlertSeverity::Critical->value,
                AlertSeverity::Major->value,
                AlertSeverity::Minor->value,
            ])
            ->orderBy('opened_at')
            ->limit(self::LIST_LIMIT)
            ->get();
        $ruleNames = AlertRule::query()
            ->where('project_id', $project->getKey())
            ->whereIn('id', $openedByRule->keys()->merge($unacknowledged->pluck('alert_rule_id')))
            ->pluck('name', 'id');
        $deviceNames = Device::query()
            ->where('project_id', $project->getKey())
            ->whereIn('id', $unacknowledged->pluck('device_id'))
            ->pluck('name', 'id');

        return [
            'active' => (clone $active)->count(),
            'active_unacknowledged' => (clone $active)->whereNull('acknowledged_at')->count(),
            'active_by_severity' => collect(AlertSeverity::cases())
                ->mapWithKeys(static fn (AlertSeverity $severity): array => [$severity->value => (int) ($bySeverity[$severity->value] ?? 0)])
                ->all(),
            'opened_in_window' => Alert::query()->where('project_id', $project->getKey())->where('opened_at', '>=', $since)->count(),
            'top_rules' => $openedByRule
                ->map(static fn (mixed $total, int|string $ruleId): array => [
                    'id' => (string) $ruleId,
                    'name' => (string) $ruleNames[$ruleId],
                    'opened' => (int) $total,
                ])
                ->values()
                ->all(),
            'unacknowledged' => $unacknowledged
                ->map(static fn (Alert $alert): array => [
                    'id' => (string) $alert->getKey(),
                    'severity' => $alert->severity->value,
                    'rule_name' => (string) $ruleNames[$alert->alert_rule_id],
                    'device_name' => (string) $deviceNames[$alert->device_id],
                    'opened_at' => $alert->opened_at->toISOString(),
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return array{totals: array<string, int>, buckets: list<IngestBucket>, top_reasons: list<array{code: string, count: int}>} */
    private function ingest(Project $project, CarbonImmutable $since, CarbonImmutable $now): array
    {
        $outcomes = DB::table('telemetry_ingest_outcomes')
            ->where('project_id', $project->getKey())
            ->where('received_at', '>=', $since);
        $totals = (clone $outcomes)
            ->select('status', DB::raw('COUNT(*) AS total'))
            ->groupBy('status')
            ->pluck('total', 'status');
        $hour = DB::getDriverName() === 'pgsql'
            ? "date_trunc('hour', received_at)"
            : "strftime('%Y-%m-%d %H:00:00', received_at)";
        $hourly = (clone $outcomes)
            ->selectRaw("$hour AS bucket, status, COUNT(*) AS total")
            ->groupByRaw("$hour, status")
            ->get();

        /** @var array<string, IngestBucket> $buckets */
        $buckets = [];
        $start = $now->startOfHour()->subHours(self::WINDOW_HOURS - 1);

        for ($index = 0; $index < self::WINDOW_HOURS; $index++) {
            $bucketStart = $start->addHours($index);
            $buckets[$bucketStart->format('Y-m-d H')] = ['start' => $bucketStart->toISOString(), 'persisted' => 0, 'rejected' => 0, 'other' => 0];
        }

        foreach ($hourly as $row) {
            $key = CarbonImmutable::parse((string) $row->bucket)->utc()->format('Y-m-d H');

            if (! isset($buckets[$key])) {
                continue;
            }

            $column = in_array($row->status, ['persisted', 'rejected'], true) ? (string) $row->status : 'other';
            $buckets[$key][$column] += (int) $row->total;
        }

        $reasons = [];
        $rejected = (clone $outcomes)
            ->where('status', 'rejected')
            ->orderByDesc('received_at')
            ->limit(self::REASON_SAMPLE)
            ->pluck('reason_codes');

        foreach ($rejected as $encoded) {
            /** @var list<array{code: string, field: string|null}> $codes */
            $codes = json_decode((string) $encoded, true, flags: JSON_THROW_ON_ERROR);

            foreach ($codes as $reason) {
                $reasons[$reason['code']] = ($reasons[$reason['code']] ?? 0) + 1;
            }
        }

        arsort($reasons);

        return [
            'totals' => collect(self::OUTCOME_STATUSES)
                ->mapWithKeys(static fn (string $status): array => [$status => (int) ($totals[$status] ?? 0)])
                ->all(),
            'buckets' => array_values($buckets),
            'top_reasons' => collect($reasons)
                ->take(5)
                ->map(static fn (int $count, string $code): array => ['code' => $code, 'count' => $count])
                ->values()
                ->all(),
        ];
    }

    /** @return Pipeline */
    private function pipeline(Project $project, CarbonImmutable $since, CarbonImmutable $now): array
    {
        $jobs = DB::table('telemetry_evaluation_jobs')->where('project_id', $project->getKey());
        $latencies = (clone $jobs)
            ->whereNotNull('evaluated_at')
            ->where('evaluated_at', '>=', $now->subHour())
            ->get(['received_at', 'evaluated_at'])
            ->map(static fn (object $row): float => (float) CarbonImmutable::parse((string) $row->received_at)
                ->diffInMilliseconds(CarbonImmutable::parse((string) $row->evaluated_at)))
            ->sort()
            ->values()
            ->all();
        $pending = (clone $jobs)->whereNull('evaluated_at');
        $outbox = DB::table('outbox_events')->where('project_id', $project->getKey())->whereNull('fanned_out_at');
        $deliveries = WebhookDelivery::query()->where('project_id', $project->getKey())->where('created_at', '>=', $since);
        $byStatus = (clone $deliveries)
            ->select('status', DB::raw('COUNT(*) AS total'))
            ->groupBy('status')
            ->pluck('total', 'status');
        $failing = (clone $deliveries)
            ->whereIn('status', [WebhookDeliveryStatus::Failed->value, WebhookDeliveryStatus::Exhausted->value])
            ->select('webhook_endpoint_id', DB::raw('COUNT(*) AS total'))
            ->groupBy('webhook_endpoint_id')
            ->orderByDesc('total')
            ->limit(5)
            ->pluck('total', 'webhook_endpoint_id');
        $endpoints = WebhookEndpoint::query()
            ->where('project_id', $project->getKey())
            ->whereIn('id', $failing->keys())
            ->pluck('name', 'id');

        return [
            'evaluation_latency_p50_ms' => self::percentile($latencies, 0.50),
            'evaluation_latency_p95_ms' => self::percentile($latencies, 0.95),
            'pending_evaluation_jobs' => (clone $pending)->count(),
            'oldest_pending_job_age_s' => self::ageSeconds((clone $pending)->min('received_at'), $now),
            'outbox_pending' => (clone $outbox)->count(),
            'oldest_outbox_age_s' => self::ageSeconds((clone $outbox)->min('created_at'), $now),
            'deliveries' => collect(WebhookDeliveryStatus::cases())
                ->mapWithKeys(static fn (WebhookDeliveryStatus $status): array => [$status->value => (int) ($byStatus[$status->value] ?? 0)])
                ->all(),
            'failing_endpoints' => $failing
                ->map(static fn (mixed $total, int|string $endpointId): array => [
                    'id' => (string) $endpointId,
                    'name' => (string) $endpoints[$endpointId],
                    'failed' => (int) $total,
                    'last_error' => WebhookDelivery::query()
                        ->where('webhook_endpoint_id', $endpointId)
                        ->whereNotNull('last_error')
                        ->latest('updated_at')
                        ->value('last_error'),
                ])
                ->values()
                ->all(),
        ];
    }

    /** @param  list<float>  $sorted */
    private static function percentile(array $sorted, float $quantile): ?float
    {
        return $sorted === [] ? null : round($sorted[(int) ceil($quantile * count($sorted)) - 1], 1);
    }

    private static function ageSeconds(mixed $timestamp, CarbonImmutable $now): ?int
    {
        return $timestamp === null ? null : (int) CarbonImmutable::parse((string) $timestamp)->diffInSeconds($now);
    }
}
