<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AlertRule\AlertRule;
use Modules\Admin\Models\AlertRule\AlertRuleRevision;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\DeviceProfile\DeviceProfileVersion;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Models\Webhook\WebhookEndpoint;
use Modules\Core\Models\User\User;

/**
 * Last-24h activity for the Overview screen of "Da Nang Packaging Operations": ingest outcomes and samples,
 * rule evaluation jobs, alerts with their history, and outbox events with webhook deliveries.
 *
 * Timestamps are relative to the moment it runs, so run it again to refresh the window:
 *   php artisan db:seed --class=DemoOverviewSeeder
 * Every row it writes is tagged `demo-overview` (message ids, idempotency keys) and is replaced on each run.
 */
final class DemoOverviewSeeder extends Seeder
{
    use WithoutModelEvents;

    private const string PROJECT = 'Da Nang Packaging Operations';

    private const string TAG = 'demo-overview';

    private const int SAMPLE_EVERY_MINUTES = 10;

    /** @var array<string, array{0: int|float, 1: int|float}> value range per profile field */
    private const array RANGES = [
        'speed' => [22, 38],
        'current' => [6.5, 10.5],
        'temperature' => [24, 42],
        'printhead_temperature' => [48, 64],
        'labels_per_min' => [80, 120],
        'humidity' => [45, 68],
    ];

    public function run(): void
    {
        mt_srand(20261019);
        $project = Project::query()->where('name', self::PROJECT)->firstOrFail();
        $admin = User::query()->where('email', 'admin@gmail.com')->firstOrFail();
        $now = CarbonImmutable::now();

        DB::transaction(function () use ($project, $admin, $now): void {
            $this->clear($project);
            $devices = Device::query()
                ->where('project_id', $project->getKey())
                ->whereNotNull('device_profile_version_id')
                ->orderBy('code')
                ->get();
            $versions = DeviceProfileVersion::query()
                ->whereIn('id', $devices->pluck('device_profile_version_id'))
                ->get()
                ->keyBy('id');

            $this->seedPresence($devices, $now);
            $this->seedTelemetry($project, $devices, $versions, $now);
            $this->seedAlerts($project, $admin, $devices, $versions, $now);
        });

        $this->command->info('Seeded the last 24h of Overview data for '.self::PROJECT.'.');
    }

    private function clear(Project $project): void
    {
        $projectId = $project->getKey();
        $outboxIds = DB::table('outbox_events')->where('project_id', $projectId)->where('idempotency_key', 'like', self::TAG.':%')->pluck('id');
        $deliveryIds = DB::table('webhook_deliveries')->whereIn('outbox_event_id', $outboxIds)->pluck('id');
        $alertIds = DB::table('alerts')->where('project_id', $projectId)->where('open_message_id', 'like', self::TAG.'%')->pluck('id');
        $sampleIds = DB::table('telemetry_samples')->where('project_id', $projectId)->where('message_id', 'like', self::TAG.'%')->pluck('id');

        DB::table('webhook_delivery_attempts')->whereIn('webhook_delivery_id', $deliveryIds)->delete();
        DB::table('webhook_deliveries')->whereIn('id', $deliveryIds)->delete();
        DB::table('outbox_events')->whereIn('id', $outboxIds)->delete();
        DB::table('alert_events')->whereIn('alert_id', $alertIds)->delete();
        DB::table('alerts')->whereIn('id', $alertIds)->delete();
        DB::table('telemetry_evaluation_jobs')->whereIn('sample_id', $sampleIds)->delete();
        DB::table('telemetry_samples')->whereIn('id', $sampleIds)->delete();
        DB::table('telemetry_ingest_outcomes')->where('project_id', $projectId)->where('message_id', 'like', self::TAG.'%')->delete();
    }

    /**
     * Most devices report now; one went quiet three hours ago and one flaps.
     *
     * @param  Collection<int, Device>  $devices
     */
    private function seedPresence(Collection $devices, CarbonImmutable $now): void
    {
        foreach ($devices->values() as $index => $device) {
            [$state, $lastSeen] = match ($index % 6) {
                4 => ['offline', $now->subHours(3)],
                5 => ['unstable', $now->subMinutes(7)],
                default => ['online', $now->subSeconds(mt_rand(5, 90))],
            };
            DB::table('devices')->where('id', $device->getKey())->update(['connection_state' => $state, 'last_seen_at' => $lastSeen]);
        }
    }

    /**
     * One message every ten minutes per device. The first label printer sends an outdated schema_version for the
     * last six hours, one conveyor sometimes sends an unknown field, and a few messages arrive twice.
     *
     * @param  Collection<int, Device>  $devices
     * @param  Collection<string, DeviceProfileVersion>  $versions
     */
    private function seedTelemetry(Project $project, Collection $devices, Collection $versions, CarbonImmutable $now): void
    {
        $outcomes = [];
        $samples = [];
        $jobs = [];
        $mismatchDevice = $devices->first(static fn (Device $device): bool => str_contains((string) $device->getAttribute('code'), 'printer'));
        $noisyDevice = $devices->first(static fn (Device $device): bool => str_contains((string) $device->getAttribute('code'), 'conveyor'));

        foreach ($devices as $device) {
            $version = $versions[(string) $device->getAttribute('device_profile_version_id')];

            for ($at = $now->subHours(24)->addMinutes(mt_rand(0, 9)); $at < $now; $at = $at->addMinutes(self::SAMPLE_EVERY_MINUTES)) {
                $messageId = self::TAG.'-'.Str::uuid();
                $reasons = [];
                $status = 'persisted';

                if ($device->is($mismatchDevice) && $at > $now->subHours(6)) {
                    $status = 'rejected';
                    $reasons[] = ['code' => 'schema_version_mismatch', 'field' => 'schema_version'];
                } elseif ($device->is($noisyDevice) && mt_rand(1, 12) === 1) {
                    $status = 'rejected';
                    $reasons[] = ['code' => 'unknown_field', 'field' => 'vibration'];
                } elseif (mt_rand(1, 60) === 1) {
                    $status = 'duplicate';
                }

                $outcomes[] = [
                    'id' => (string) Str::uuid(),
                    'project_id' => $project->getKey(),
                    'device_id' => $device->getKey(),
                    'message_id' => $messageId,
                    'status' => $status,
                    'reason_codes' => json_encode($reasons),
                    'received_at' => $at,
                    'processing_id' => (string) Str::uuid(),
                ];

                if ($status !== 'persisted') {
                    continue;
                }

                $sampleId = (string) Str::uuid();
                $samples[] = [
                    'id' => $sampleId,
                    'project_id' => $project->getKey(),
                    'device_id' => $device->getKey(),
                    'device_profile_version_id' => $version->getKey(),
                    'message_id' => $messageId,
                    'occurred_at' => $at->subSeconds(1),
                    'received_at' => $at,
                    'values' => json_encode($this->values($version)),
                    'status' => 'persisted',
                ];

                // Rule evaluation latency covers the last hour; the newest samples are still waiting.
                if ($at > $now->subHour()) {
                    $jobs[] = [
                        'sample_id' => $sampleId,
                        'project_id' => $project->getKey(),
                        'device_id' => $device->getKey(),
                        'device_profile_version_id' => $version->getKey(),
                        'equipment_asset_id' => $device->getAttribute('equipment_asset_id'),
                        // Microseconds kept explicitly: a bare Carbon is written to the second and the latency would read 0 ms.
                        'received_at' => $at->format('Y-m-d H:i:s.uP'),
                        'evaluated_at' => $at > $now->subMinutes(2) ? null : $at->addMilliseconds(mt_rand(40, 450))->format('Y-m-d H:i:s.uP'),
                    ];
                }
            }
        }

        foreach (array_chunk($outcomes, 500) as $chunk) {
            DB::table('telemetry_ingest_outcomes')->insert($chunk);
        }

        foreach (array_chunk($samples, 500) as $chunk) {
            DB::table('telemetry_samples')->insert($chunk);
        }

        DB::table('telemetry_evaluation_jobs')->insert($jobs);
    }

    /** @return array<string, int|float|string> */
    private function values(DeviceProfileVersion $version): array
    {
        $values = [];

        foreach ($version->definition['fields'] as $name => $field) {
            if ($field['type'] === 'string') {
                $values[$name] = $field['allowed_values'][0] ?? 'running';

                continue;
            }

            [$min, $max] = self::RANGES[$name] ?? [0, 100];
            $value = $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
            $values[$name] = $field['type'] === 'integer' ? (int) round($value) : round($value, 2);
        }

        return $values;
    }

    /**
     * Alerts in every state, each on a device whose profile owns the rule, plus their events, outbox entries and
     * webhook deliveries. Deliveries to the maintenance endpoint keep failing.
     *
     * @param  Collection<int, Device>  $devices
     * @param  Collection<string, DeviceProfileVersion>  $versions
     */
    private function seedAlerts(Project $project, User $admin, Collection $devices, Collection $versions, CarbonImmutable $now): void
    {
        // [rule code, opened minutes ago, cleared minutes ago or null, acknowledged, which matching device]
        $specs = [
            ['conveyor-stalled', 40, null, false, 0],
            ['conveyor-overcurrent', 130, null, false, 1],
            ['conveyor-overcurrent', 900, 860, true, 0],
            ['conveyor-overcurrent', 400, 370, false, 2],
            ['printhead-hot', 300, null, true, 0],
            ['printhead-hot', 1200, 1150, false, 1],
            ['warehouse-humid', 30, null, false, 0],
            ['warehouse-humid', 600, 540, false, 1],
            ['conveyor-stalled', 1300, 1280, true, 1],
        ];
        $endpoints = WebhookEndpoint::query()->where('project_id', $project->getKey())->where('enabled', true)->get();

        foreach ($specs as $index => [$code, $openedAgo, $clearedAgo, $acknowledged, $deviceIndex]) {
            $rule = AlertRule::query()->where('project_id', $project->getKey())->where('code', $code)->where('status', 'active')->first();

            if ($rule === null) {
                continue;
            }

            $matching = $devices
                ->filter(static fn (Device $device): bool => $versions[(string) $device->getAttribute('device_profile_version_id')]->getAttribute('device_profile_id') === $rule->device_profile_id)
                ->values();
            $device = $matching[$deviceIndex % max(1, $matching->count())] ?? null;

            // One active alert per device and rule: skip a spec that would open a second one.
            if ($device === null || ($clearedAgo === null && DB::table('alerts')
                ->where('device_id', $device->getKey())->where('alert_rule_id', $rule->getKey())->whereNull('cleared_at')->exists())) {
                continue;
            }

            /** @var AlertRuleRevision $revision */
            $revision = $rule->currentRevision()->firstOrFail();
            $definition = $revision->definition;
            $openedAt = $now->subMinutes($openedAgo);
            $clearedAt = $clearedAgo === null ? null : $now->subMinutes($clearedAgo);
            $acknowledgedAt = $acknowledged ? $openedAt->addMinutes(12) : null;
            $alertId = (string) Str::uuid();
            $openMessage = self::TAG.'-alert-'.$index;

            DB::table('alerts')->insert([
                'id' => $alertId,
                'project_id' => $project->getKey(),
                'device_id' => $device->getKey(),
                'equipment_asset_id' => $device->getAttribute('equipment_asset_id'),
                'alert_rule_id' => $rule->getKey(),
                'alert_rule_revision_id' => $revision->getKey(),
                'device_profile_version_id' => $device->getAttribute('device_profile_version_id'),
                'severity' => $definition['severity'],
                'opened_at' => $openedAt,
                'last_seen_at' => $clearedAt ?? $now->subMinutes(1),
                'cleared_at' => $clearedAt,
                'clear_reason' => $clearedAt === null ? null : 'condition_cleared',
                'acknowledged_at' => $acknowledgedAt,
                'acknowledged_by' => $acknowledged ? $admin->getKey() : null,
                'open_message_id' => $openMessage,
                'clear_message_id' => $clearedAt === null ? null : $openMessage.'-clear',
                'evidence' => json_encode([
                    'field' => $definition['field'],
                    'value' => $definition['trigger']['value'] + ($definition['trigger']['operator'] === 'lt' || $definition['trigger']['operator'] === 'lte' ? -2 : 2),
                    'operator' => $definition['trigger']['operator'],
                    'threshold' => $definition['trigger']['value'],
                ]),
                'created_at' => $openedAt,
                'updated_at' => $clearedAt ?? $acknowledgedAt ?? $openedAt,
            ]);

            $events = array_filter([
                'opened' => $openedAt,
                'acknowledged' => $acknowledgedAt,
                'cleared' => $clearedAt,
            ]);

            foreach ($events as $type => $occurredAt) {
                DB::table('alert_events')->insert([
                    'id' => (string) Str::uuid(),
                    'project_id' => $project->getKey(),
                    'alert_id' => $alertId,
                    'type' => $type,
                    'occurred_at' => $occurredAt,
                    'actor_id' => $type === 'acknowledged' ? $admin->getKey() : null,
                    'alert_rule_revision_id' => $revision->getKey(),
                    'message_id' => $type === 'opened' ? $openMessage : null,
                    'data' => json_encode($type === 'cleared' ? ['clear_reason' => 'condition_cleared'] : []),
                ]);
                $this->seedDelivery($project, $endpoints, (string) $rule->getKey(), $alertId, "alert.$type", $occurredAt, $now);
            }
        }
    }

    /** @param  Collection<int, WebhookEndpoint>  $endpoints */
    private function seedDelivery(Project $project, Collection $endpoints, string $ruleId, string $alertId, string $eventType, CarbonImmutable $at, CarbonImmutable $now): void
    {
        $outboxId = (string) Str::uuid();
        // The newest event is still waiting for fan-out, so the outbox backlog is visible.
        $pendingFanOut = $at > $now->subMinutes(35);

        DB::table('outbox_events')->insert([
            'id' => $outboxId,
            'project_id' => $project->getKey(),
            'event_type' => $eventType,
            'aggregate_id' => $alertId,
            'idempotency_key' => self::TAG.":$alertId:$eventType",
            'payload' => json_encode(['type' => $eventType, 'project_id' => $project->getKey(), 'alert' => ['id' => $alertId, 'rule_id' => $ruleId]]),
            'created_at' => $at,
            'fanned_out_at' => $pendingFanOut ? null : $at->addSeconds(2),
        ]);

        if ($pendingFanOut) {
            return;
        }

        foreach ($endpoints as $endpoint) {
            if (! in_array($ruleId, $endpoint->alert_rule_ids, true) || ! in_array($eventType, $endpoint->event_types, true)) {
                continue;
            }

            $failing = str_contains($endpoint->name, 'Maintenance');
            $attempts = $failing ? 8 : (mt_rand(1, 5) === 1 ? 2 : 1);
            $deliveryId = (string) Str::uuid();

            DB::table('webhook_deliveries')->insert([
                'id' => $deliveryId,
                'project_id' => $project->getKey(),
                'outbox_event_id' => $outboxId,
                'webhook_endpoint_id' => $endpoint->getKey(),
                'status' => $failing ? 'exhausted' : 'succeeded',
                'attempts' => $attempts,
                'next_attempt_at' => $at,
                'last_status_code' => $failing ? 503 : 200,
                'last_error' => $failing ? 'http_503' : null,
                'delivered_at' => $failing ? null : $at->addSeconds(3),
                'created_at' => $at,
                'updated_at' => $at->addMinutes($failing ? 40 : 0),
            ]);

            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                $succeeded = ! $failing && $attempt === $attempts;
                DB::table('webhook_delivery_attempts')->insert([
                    'id' => (string) Str::uuid(),
                    'webhook_delivery_id' => $deliveryId,
                    'attempt_no' => $attempt,
                    'started_at' => $at->addSeconds($attempt * 5),
                    'finished_at' => $at->addSeconds($attempt * 5)->addMilliseconds(180),
                    'status_code' => $succeeded ? 200 : 503,
                    'error' => $succeeded ? null : 'http_503',
                    'duration_ms' => mt_rand(80, 400),
                ]);
            }
        }
    }
}
