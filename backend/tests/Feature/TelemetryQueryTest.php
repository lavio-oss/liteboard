<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Admin\Models\DeviceProfile\DeviceProfileVersion;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

/** @return array{tenant: Tenant, project: Project, device: Device, owner: User, base: string} */
function telemetryDeviceFixture(): array
{
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);
    $project = Project::factory()->for($tenant)->create();
    $version = DeviceProfileVersion::factory()->forProfile(DeviceProfile::factory()->for($project)->create())->create();
    $device = Device::factory()->for($project)->create();
    $device->profileVersion()->associate($version);
    $device->save();

    return [
        'tenant' => $tenant,
        'project' => $project,
        'device' => $device,
        'owner' => $owner,
        'base' => "/api/tenants/$tenant->id/projects/$project->id/devices/$device->id",
    ];
}

/** @param  array<string, mixed>  $values */
function insertTelemetrySample(Device $device, string $messageId, string $occurredAt, array $values, ?string $receivedAt = null, ?string $id = null): string
{
    $id ??= (string) Str::uuid();
    DB::table('telemetry_samples')->insert([
        'id' => $id,
        'project_id' => $device->getAttribute('project_id'),
        'device_id' => $device->getKey(),
        'device_profile_version_id' => $device->getAttribute('device_profile_version_id'),
        'message_id' => $messageId,
        'occurred_at' => CarbonImmutable::parse($occurredAt)->utc()->format('Y-m-d H:i:s.uP'),
        'received_at' => CarbonImmutable::parse($receivedAt ?? $occurredAt)->utc()->format('Y-m-d H:i:s.uP'),
        'values' => json_encode($values, JSON_THROW_ON_ERROR),
    ]);

    return $id;
}

function insertTelemetryLatestValue(Device $device, string $metric, int|float|string|bool $value, string $messageId, string $occurredAt): void
{
    $at = CarbonImmutable::parse($occurredAt)->utc()->format('Y-m-d H:i:s.uP');
    DB::table('telemetry_latest_values')->insert([
        'project_id' => $device->getAttribute('project_id'),
        'device_id' => $device->getKey(),
        'metric' => $metric,
        'value' => json_encode($value, JSON_THROW_ON_ERROR),
        'sample_id' => (string) Str::uuid(),
        'message_id' => $messageId,
        'occurred_at' => $at,
        'received_at' => $at,
    ]);
}

/** @param  list<array{code: string, field: string|null}>  $reasons */
function insertTelemetryOutcome(Device $device, ?string $messageId, string $status, string $receivedAt, array $reasons = []): string
{
    $processingId = (string) Str::uuid();
    DB::table('telemetry_ingest_outcomes')->insert([
        'id' => (string) Str::uuid(),
        'project_id' => $device->getAttribute('project_id'),
        'device_id' => $device->getKey(),
        'processing_id' => $processingId,
        'message_id' => $messageId,
        'status' => $status,
        'reason_codes' => json_encode($reasons, JSON_THROW_ON_ERROR),
        'stages' => json_encode([
            ['stage' => 'received', 'at' => $receivedAt],
            ['stage' => 'commit', 'at' => $receivedAt],
        ], JSON_THROW_ON_ERROR),
        'received_at' => CarbonImmutable::parse($receivedAt)->utc()->format('Y-m-d H:i:s.uP'),
    ]);

    return $processingId;
}

it('returns the latest value of every metric even when the newest sample omits one', function (): void {
    $fixture = telemetryDeviceFixture();
    insertTelemetryLatestValue($fixture['device'], 'temperature', 60, 'newest', '2026-10-13T10:00:10Z');
    insertTelemetryLatestValue($fixture['device'], 'rpm', 1200, 'older', '2026-10-13T10:00:05Z');

    $this->actingAs($fixture['owner'])
        ->getJson($fixture['base'].'/telemetry/latest')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.metric', 'rpm')
        ->assertJsonPath('data.0.value', 1200)
        ->assertJsonPath('data.0.message_id', 'older')
        ->assertJsonPath('data.0.occurred_at', '2026-10-13T10:00:05.000000Z')
        ->assertJsonPath('data.1.metric', 'temperature')
        ->assertJsonPath('data.1.value', 60);
});

it('returns no latest values for a device without telemetry', function (): void {
    $fixture = telemetryDeviceFixture();

    $this->actingAs($fixture['owner'])
        ->getJson($fixture['base'].'/telemetry/latest')
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('reads profile-less samples through latest history and message trace APIs', function (): void {
    $fixture = telemetryDeviceFixture();
    DB::table('devices')->where('id', $fixture['device']->getKey())->update(['device_profile_version_id' => null]);
    $device = Device::query()->findOrFail($fixture['device']->getKey());
    $sampleId = insertTelemetrySample(
        $device,
        'profileless-message',
        '2026-10-13T10:00:00Z',
        ['temperature' => 42, 'mode' => 'auto'],
        id: '00000000-0000-4000-8000-000000000041',
    );
    insertTelemetryLatestValue($device, 'temperature', 42, 'profileless-message', '2026-10-13T10:00:00Z');
    insertTelemetryOutcome($device, 'profileless-message', 'persisted', '2026-10-13T10:00:01Z');

    $this->actingAs($fixture['owner'])
        ->getJson($fixture['base'].'/telemetry/latest')
        ->assertOk()
        ->assertJsonPath('data.0.metric', 'temperature')
        ->assertJsonPath('data.0.value', 42);

    $this->actingAs($fixture['owner'])
        ->getJson($fixture['base'].'/telemetry?from=2026-10-13T09:00:00Z&to=2026-10-13T11:00:00Z')
        ->assertOk()
        ->assertJsonPath('data.0.id', $sampleId)
        ->assertJsonPath('data.0.device_profile_version_id', null)
        ->assertJsonPath('data.0.values', ['temperature' => 42, 'mode' => 'auto']);

    $this->actingAs($fixture['owner'])
        ->getJson($fixture['base'].'/messages/profileless-message/trace')
        ->assertOk()
        ->assertJsonPath('data.sample.id', $sampleId)
        ->assertJsonPath('data.sample.device_profile_version_id', null)
        ->assertJsonPath('data.sample.values.temperature', 42);
});

it('cascades latest values when their device is deleted', function (): void {
    $fixture = telemetryDeviceFixture();
    insertTelemetryLatestValue($fixture['device'], 'temperature', 60, 'm', '2026-10-13T10:00:00Z');

    DB::table('devices')->where('id', $fixture['device']->getKey())->delete();

    $this->assertDatabaseMissing('telemetry_latest_values', ['device_id' => $fixture['device']->getKey()]);
});

it('pages history over a half-open range with a stable cursor across equal timestamps', function (): void {
    $fixture = telemetryDeviceFixture();
    $ids = [
        '00000000-0000-4000-8000-000000000001',
        '00000000-0000-4000-8000-000000000002',
        '00000000-0000-4000-8000-000000000003',
    ];
    foreach ($ids as $index => $id) {
        insertTelemetrySample($fixture['device'], "tie-$index", '2026-10-13T10:00:00Z', ['temperature' => $index, 'rpm' => 1], id: $id);
    }
    insertTelemetrySample($fixture['device'], 'at-to', '2026-10-13T11:00:00Z', ['temperature' => 99]);
    insertTelemetrySample($fixture['device'], 'before-from', '2026-10-13T09:59:59Z', ['temperature' => 98]);

    $query = 'from=2026-10-13T10:00:00Z&to=2026-10-13T11:00:00Z&limit=2&metrics[]=temperature';
    $first = $this->actingAs($fixture['owner'])
        ->getJson($fixture['base']."/telemetry?$query")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $ids[0])
        ->assertJsonPath('data.1.id', $ids[1])
        ->assertJsonPath('data.0.values', ['temperature' => 0]);

    $cursor = $first->json('meta.next_cursor');
    expect($cursor)->toBeString();

    $this->actingAs($fixture['owner'])
        ->getJson($fixture['base']."/telemetry?$query&cursor=".urlencode($cursor))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ids[2])
        ->assertJsonPath('meta.next_cursor', null);
});

it('rejects invalid history ranges, oversized ranges and malformed cursors', function (): void {
    $fixture = telemetryDeviceFixture();
    $request = fn (string $query) => $this->actingAs($fixture['owner'])->getJson($fixture['base']."/telemetry?$query");

    $request('from=2026-10-13T10:00:00Z&to=2026-10-13T09:00:00Z')->assertUnprocessable()->assertJsonValidationErrors('to');
    $request('from=2026-09-01T00:00:00Z&to=2026-10-13T00:00:00Z')->assertUnprocessable()->assertJsonValidationErrors('to');
    $request('from=2026-10-13 10:00:00&to=2026-10-13T11:00:00Z')->assertUnprocessable()->assertJsonValidationErrors('from');
    $request('from=2026-10-13T10:00:00Z&to=2026-10-13T11:00:00Z&limit=1001')->assertUnprocessable()->assertJsonValidationErrors('limit');
    $request('from=2026-10-13T10:00:00Z&to=2026-10-13T11:00:00Z&cursor=bm90LWEtY3Vyc29y')->assertUnprocessable()->assertJsonValidationErrors('cursor');
    $request('from=2026-10-13T10:00:00Z&to=2026-10-13T11:00:00Z&metrics[]=1bad')->assertUnprocessable()->assertJsonValidationErrors('metrics.0');
});

it('accepts Z and numeric offsets in history timestamps and filters in UTC', function (string $from, string $to): void {
    $fixture = telemetryDeviceFixture();
    insertTelemetrySample($fixture['device'], 'inside', '2026-10-13T10:30:00Z', ['temperature' => 1]);
    insertTelemetrySample($fixture['device'], 'outside', '2026-10-13T11:30:00Z', ['temperature' => 2]);

    $this->actingAs($fixture['owner'])
        ->getJson($fixture['base'].'/telemetry?from='.urlencode($from).'&to='.urlencode($to))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.message_id', 'inside');
})->with([
    'Z' => ['2026-10-13T10:00:00Z', '2026-10-13T11:00:00Z'],
    '+00:00 with microseconds' => ['2026-10-13T10:00:00.000000+00:00', '2026-10-13T11:00:00.000000+00:00'],
    '+07:00 with milliseconds' => ['2026-10-13T17:00:00.000+07:00', '2026-10-13T18:00:00.000+07:00'],
]);

it('rejects aggregate ranges that exceed the bucket limit', function (): void {
    $fixture = telemetryDeviceFixture();

    $this->actingAs($fixture['owner'])
        ->getJson($fixture['base'].'/telemetry/aggregates?from=2026-10-01T00:00:00Z&to=2026-10-13T00:00:00Z&interval=1m')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('interval');
});

it('aggregates numeric metrics into buckets anchored at from', function (): void {
    $fixture = telemetryDeviceFixture();
    DB::table('devices')->where('id', $fixture['device']->getKey())->update(['device_profile_version_id' => null]);
    $unprofiledDevice = Device::query()->findOrFail($fixture['device']->getKey());
    insertTelemetrySample($unprofiledDevice, 'a1', '2026-10-13T10:00:10Z', ['temperature' => 10, 'mode' => 'auto']);
    insertTelemetrySample($unprofiledDevice, 'a2', '2026-10-13T10:00:50Z', ['temperature' => 30]);
    insertTelemetrySample($unprofiledDevice, 'a3', '2026-10-13T10:01:10Z', ['temperature' => 5]);

    $this->actingAs($fixture['owner'])
        ->getJson($fixture['base'].'/telemetry/aggregates?from=2026-10-13T10:00:00Z&to=2026-10-13T10:02:00Z&interval=1m')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.bucket_start', '2026-10-13T10:00:00.000000Z')
        ->assertJsonPath('data.0.metric', 'temperature')
        ->assertJsonPath('data.0.avg', 20)
        ->assertJsonPath('data.0.min', 10)
        ->assertJsonPath('data.0.max', 30)
        ->assertJsonPath('data.0.count', 2)
        ->assertJsonPath('data.1.count', 1);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'Aggregation requires PostgreSQL.');

it('isolates telemetry, traces and stream tickets across tenants and projects', function (): void {
    $a = telemetryDeviceFixture();
    $b = telemetryDeviceFixture();
    $sibling = Device::factory()->for(Project::factory()->for($a['tenant'])->create())->create();
    insertTelemetrySample($b['device'], 'secret', '2026-10-13T10:00:00Z', ['temperature' => 1]);
    insertTelemetryLatestValue($b['device'], 'temperature', 1, 'secret', '2026-10-13T10:00:00Z');
    insertTelemetryOutcome($b['device'], 'secret', 'persisted', '2026-10-13T10:00:00Z');
    $range = 'from=2026-10-13T00:00:00Z&to=2026-10-14T00:00:00Z';

    foreach (['/telemetry/latest', "/telemetry?$range", '/messages', '/messages/secret/trace'] as $path) {
        $this->actingAs($a['owner'])->getJson($b['base'].$path)->assertNotFound();
        $this->actingAs($a['owner'])
            ->getJson("/api/tenants/{$a['tenant']->id}/projects/{$a['project']->id}/devices/$sibling->id$path")
            ->assertNotFound();
    }
    $this->actingAs($a['owner'])->postJson($b['base'].'/telemetry/stream-ticket')->assertNotFound();

    $this->actingAs($a['owner'])->getJson($a['base'].'/messages/secret/trace')->assertNotFound();
});

it('lets project viewers read telemetry', function (): void {
    $fixture = telemetryDeviceFixture();
    $viewer = User::factory()->create();
    $fixture['tenant']->users()->attach($viewer, ['role' => 'member']);
    $fixture['project']->users()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($viewer)->getJson($fixture['base'].'/telemetry/latest')->assertOk();
});

it('forbids tenant members outside the project from reading telemetry or streaming it', function (): void {
    $fixture = telemetryDeviceFixture();
    $outsider = User::factory()->create();
    $fixture['tenant']->users()->attach($outsider, ['role' => 'member']);

    $this->actingAs($outsider)->getJson($fixture['base'].'/telemetry/latest')->assertForbidden();
    $this->actingAs($outsider)->postJson($fixture['base'].'/telemetry/stream-ticket')->assertForbidden();
});

it('traces a message by message id across outcomes and returns its sample without raw payloads', function (): void {
    $fixture = telemetryDeviceFixture();
    insertTelemetrySample($fixture['device'], 'msg-1', '2026-10-13T10:00:00Z', ['temperature' => 42]);
    $persisted = insertTelemetryOutcome($fixture['device'], 'msg-1', 'persisted', '2026-10-13T10:00:01Z');
    $duplicate = insertTelemetryOutcome($fixture['device'], 'msg-1', 'duplicate', '2026-10-13T10:00:02Z');

    $response = $this->actingAs($fixture['owner'])
        ->getJson($fixture['base'].'/messages/msg-1/trace')
        ->assertOk()
        ->assertJsonCount(2, 'data.outcomes')
        ->assertJsonPath('data.outcomes.0.processing_id', $persisted)
        ->assertJsonPath('data.outcomes.0.stages.0.stage', 'received')
        ->assertJsonPath('data.outcomes.1.processing_id', $duplicate)
        ->assertJsonPath('data.sample.values.temperature', 42);

    expect($response->getContent())->not->toContain('raw')->not->toContain('token')->not->toContain('password');

    $this->actingAs($fixture['owner'])
        ->getJson($fixture['base']."/messages/$duplicate/trace")
        ->assertOk()
        ->assertJsonCount(2, 'data.outcomes');
});

it('traces a malformed message without message id by processing id and lists recent outcomes', function (): void {
    $fixture = telemetryDeviceFixture();
    $older = insertTelemetryOutcome($fixture['device'], 'ok', 'persisted', '2026-10-13T10:00:00Z');
    $malformed = insertTelemetryOutcome(
        $fixture['device'],
        null,
        'rejected',
        '2026-10-13T10:00:05Z',
        [['code' => 'invalid_payload', 'field' => null]],
    );

    $this->actingAs($fixture['owner'])
        ->getJson($fixture['base'].'/messages?limit=10')
        ->assertOk()
        ->assertJsonPath('data.0.processing_id', $malformed)
        ->assertJsonPath('data.1.processing_id', $older);

    $this->actingAs($fixture['owner'])
        ->getJson($fixture['base']."/messages/$malformed/trace")
        ->assertOk()
        ->assertJsonCount(1, 'data.outcomes')
        ->assertJsonPath('data.outcomes.0.reason_codes.0.code', 'invalid_payload')
        ->assertJsonPath('data.sample', null);
});

it('issues a signed short-lived stream ticket scoped to the device', function (): void {
    config(['services.telemetry.ws_secret' => str_repeat('s', 32)]);
    $fixture = telemetryDeviceFixture();

    $ticket = $this->actingAs($fixture['owner'])
        ->postJson($fixture['base'].'/telemetry/stream-ticket')
        ->assertCreated()
        ->json('data.ticket');

    [$payload, $signature] = explode('.', $ticket);
    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, str_repeat('s', 32), true)), '+/', '-_'), '=');
    $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);

    expect($signature)->toBe($expected)
        ->and($claims['device_id'])->toBe($fixture['device']->getKey())
        ->and($claims['project_id'])->toBe($fixture['project']->getKey())
        ->and($claims['sub'])->toBe((string) $fixture['owner']->getKey())
        ->and($claims['exp'])->toBeLessThanOrEqual(now()->addSeconds(60)->getTimestamp());
});

it('shows an unresolved outcome for a blocked device inside its own project trace', function (): void {
    $fixture = telemetryDeviceFixture();
    $processingId = insertTelemetryOutcome(
        $fixture['device'],
        'pending-msg',
        'unresolved',
        '2026-10-13T10:00:00Z',
        [['code' => 'device_not_approved', 'field' => null]],
    );

    $this->actingAs($fixture['owner'])
        ->getJson($fixture['base'].'/messages/pending-msg/trace')
        ->assertOk()
        ->assertJsonPath('data.outcomes.0.processing_id', $processingId)
        ->assertJsonPath('data.outcomes.0.status', 'unresolved')
        ->assertJsonPath('data.outcomes.0.reason_codes.0.code', 'device_not_approved')
        ->assertJsonPath('data.sample', null);
});

function insertUnresolvedMessage(string $reason, string $receivedAt): string
{
    $processingId = (string) Str::uuid();
    DB::table('telemetry_unresolved_messages')->insert([
        'processing_id' => $processingId,
        'topic' => 'liteboard/not-a-uuid/devices/x/telemetry',
        'reason' => $reason,
        'payload_size' => 42,
        'stages' => json_encode([['stage' => 'received', 'at' => $receivedAt]], JSON_THROW_ON_ERROR),
        'received_at' => CarbonImmutable::parse($receivedAt)->utc()->format('Y-m-d H:i:s.uP'),
    ]);

    return $processingId;
}

it('lets platform admins list and trace unresolved messages', function (): void {
    $admin = User::factory()->create(['system_role' => 'platform_admin']);
    $older = insertUnresolvedMessage('device_not_found', '2026-10-13T10:00:00Z');
    $newer = insertUnresolvedMessage('invalid_topic', '2026-10-13T10:00:05Z');

    $this->actingAs($admin)
        ->getJson('/api/platform/telemetry/unresolved-messages?limit=10')
        ->assertOk()
        ->assertJsonPath('data.0.processing_id', $newer)
        ->assertJsonPath('data.0.reason', 'invalid_topic')
        ->assertJsonPath('data.1.processing_id', $older);

    $this->actingAs($admin)
        ->getJson("/api/platform/telemetry/unresolved-messages/$older")
        ->assertOk()
        ->assertJsonPath('data.reason', 'device_not_found')
        ->assertJsonPath('data.payload_size', 42)
        ->assertJsonPath('data.stages.0.stage', 'received');

    $this->actingAs($admin)
        ->getJson('/api/platform/telemetry/unresolved-messages/'.Str::uuid())
        ->assertNotFound();
});

it('forbids tenant owners from reading central unresolved messages', function (): void {
    $fixture = telemetryDeviceFixture();
    $processingId = insertUnresolvedMessage('device_not_found', '2026-10-13T10:00:00Z');

    $this->actingAs($fixture['owner'])
        ->getJson('/api/platform/telemetry/unresolved-messages')
        ->assertForbidden();
    $this->actingAs($fixture['owner'])
        ->getJson("/api/platform/telemetry/unresolved-messages/$processingId")
        ->assertForbidden();
});

it('lists and traces sub-device messages from their gateway only', function (): void {
    $fixture = telemetryDeviceFixture();
    $gateway = Device::factory()->for($fixture['project'])->gateway()->create();
    $subDevice = Device::factory()->subDeviceOf($gateway)->create();
    $otherGateway = Device::factory()->for($fixture['project'])->gateway()->create();
    $foreignSubDevice = Device::factory()->subDeviceOf($otherGateway)->create();
    insertTelemetryOutcome($subDevice, 'sub-msg', 'persisted', '2026-10-28T08:00:00Z');
    insertTelemetrySample($subDevice, 'sub-msg', '2026-10-28T08:00:00Z', ['temperature' => 21.5]);
    insertTelemetryOutcome($foreignSubDevice, 'foreign-msg', 'persisted', '2026-10-28T08:00:01Z');
    $gatewayBase = "/api/tenants/{$fixture['tenant']->id}/projects/{$fixture['project']->id}/devices/$gateway->id";

    $this->actingAs($fixture['owner'])
        ->getJson("$gatewayBase/messages?limit=10")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.device_id', $subDevice->id)
        ->assertJsonPath('data.0.message_id', 'sub-msg');

    $this->actingAs($fixture['owner'])
        ->getJson("$gatewayBase/messages/sub-msg/trace")
        ->assertOk()
        ->assertJsonPath('data.sample.values.temperature', 21.5);

    $this->actingAs($fixture['owner'])->getJson("$gatewayBase/messages/foreign-msg/trace")->assertNotFound();
    $this->actingAs($fixture['owner'])->getJson($fixture['base'].'/messages/sub-msg/trace')->assertNotFound();
});
