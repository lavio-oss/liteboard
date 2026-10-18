<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\Alert\OutboxEvent;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Admin\Models\DeviceProfile\DeviceProfileVersion;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Models\Webhook\WebhookDelivery;
use Modules\Admin\Models\Webhook\WebhookEndpoint;
use Modules\Core\Models\Tenant\Tenant;

it('reports evaluation latency, backlog, outbox age and delivery outcomes', function (): void {
    $this->freezeSecond();
    $project = Project::factory()->for(Tenant::factory())->create();
    $version = DeviceProfileVersion::factory()->forProfile(DeviceProfile::factory()->for($project)->create())->create();
    $device = Device::factory()->for($project)->create(['device_profile_version_id' => $version->getKey()]);
    $now = now()->toImmutable();

    foreach ([[100, true], [200, true], [300, true], [0, false]] as $index => [$latencyMs, $evaluated]) {
        $sampleId = (string) Str::uuid();
        $receivedAt = $now->subSeconds(10 + $index);
        DB::table('telemetry_samples')->insert([
            'id' => $sampleId, 'project_id' => $project->getKey(), 'device_id' => $device->getKey(),
            'device_profile_version_id' => $version->getKey(), 'message_id' => "m-$index", 'occurred_at' => $receivedAt,
            'received_at' => $receivedAt, 'values' => '{"temperature": 85}', 'status' => 'persisted',
        ]);
        DB::table('telemetry_evaluation_jobs')->insert([
            'sample_id' => $sampleId, 'project_id' => $project->getKey(), 'device_id' => $device->getKey(),
            'device_profile_version_id' => $version->getKey(), 'received_at' => $receivedAt->format('Y-m-d H:i:s.u'),
            'evaluated_at' => $evaluated ? $receivedAt->addMilliseconds($latencyMs)->format('Y-m-d H:i:s.u') : null,
        ]);
    }

    $eventId = (string) Str::uuid();
    OutboxEvent::query()->insert([
        'id' => $eventId, 'project_id' => $project->getKey(), 'event_type' => 'alert.opened', 'aggregate_id' => (string) Str::uuid(),
        'idempotency_key' => 'k-1', 'payload' => '{}', 'created_at' => $now->subSeconds(42),
    ]);
    $endpoint = WebhookEndpoint::factory()->for($project)->create();
    $event = OutboxEvent::query()->findOrFail($eventId);
    WebhookDelivery::factory()->forEvent($event, $endpoint)->create(['status' => 'succeeded', 'attempts' => 3]);

    $this->artisan('alerting:stats')
        ->expectsTable(['metric', 'value'], [
            ['evaluations (window)', 3],
            ['evaluation latency p50 ms', '200.0'],
            ['evaluation latency p95 ms', '300.0'],
            ['pending evaluation jobs', 1],
            ['oldest pending job age s', '13'],
            ['outbox pending fan-out', 1],
            ['oldest outbox age s', '42'],
            ['deliveries pending', 0],
            ['deliveries in_flight', 0],
            ['deliveries succeeded', 1],
            ['deliveries failed', 0],
            ['deliveries exhausted', 0],
            ['deliveries retried', 1],
            ['deliveries recovered after retry', 1],
        ])
        ->assertSuccessful();
});
