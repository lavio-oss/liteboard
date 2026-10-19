<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\Alert\Alert;
use Modules\Admin\Models\Alert\OutboxEvent;
use Modules\Admin\Models\AlertRule\AlertRule;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Admin\Models\DeviceProfile\DeviceProfileVersion;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Models\Webhook\WebhookDelivery;
use Modules\Admin\Models\Webhook\WebhookEndpoint;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

/** @return array{owner: User, tenant: Tenant, project: Project, base: string} */
function overviewFixture(): array
{
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);
    $project = Project::factory()->for($tenant)->create();

    return [
        'owner' => $owner,
        'tenant' => $tenant,
        'project' => $project,
        'base' => "/api/tenants/$tenant->id/projects/$project->id/overview",
    ];
}

/** @param  list<array{code: string, field: string|null}>  $reasons */
function overviewOutcome(Device $device, string $status, CarbonImmutable $receivedAt, array $reasons = []): void
{
    DB::table('telemetry_ingest_outcomes')->insert([
        'id' => (string) Str::uuid(),
        'project_id' => $device->getAttribute('project_id'),
        'device_id' => $device->getKey(),
        'message_id' => (string) Str::uuid(),
        'status' => $status,
        'reason_codes' => json_encode($reasons),
        'received_at' => $receivedAt->format('Y-m-d H:i:s.u'),
        'processing_id' => (string) Str::uuid(),
    ]);
}

function overviewMember(Tenant $tenant, Project $project, string $role): User
{
    $member = User::factory()->create();
    $tenant->users()->attach($member, ['role' => 'member']);
    $project->users()->attach($member, ['role' => $role]);

    return $member;
}

it('summarises devices, alerts and ingest of one project', function (): void {
    ['owner' => $owner, 'project' => $project, 'base' => $base] = overviewFixture();
    $now = CarbonImmutable::parse('2026-10-19 12:30:00');
    $this->travelTo($now);
    $profile = DeviceProfile::factory()->for($project)->create();
    $version = DeviceProfileVersion::factory()->forProfile($profile)->create();
    $online = Device::factory()->for($project)->create(['connection_state' => 'online', 'device_profile_version_id' => $version->getKey()]);
    $quiet = Device::factory()->for($project)->create(['device_profile_version_id' => $version->getKey(), 'last_seen_at' => $now->subDay()]);
    $pending = Device::factory()->for($project)->create(['approval_status' => 'pending']);
    $rule = AlertRule::factory()->forProfile($profile)->withRevision()->create(['name' => 'Overheat']);
    Alert::factory()->forRuleAndDevice($rule->refresh(), $online)->create(['severity' => 'critical', 'opened_at' => $now->subHour()]);
    Alert::factory()->forRuleAndDevice($rule, $quiet)->create(['severity' => 'major', 'acknowledged_at' => $now, 'opened_at' => $now->subHours(30)]);
    Alert::factory()->forRuleAndDevice($rule, $pending)->cleared()->create(['opened_at' => $now->subHours(2)]);
    overviewOutcome($online, 'persisted', $now->subMinutes(5));
    overviewOutcome($online, 'persisted', $now->subMinutes(70));
    overviewOutcome($online, 'rejected', $now->subMinutes(10), [['code' => 'schema_version_mismatch', 'field' => 'schema_version']]);
    overviewOutcome($online, 'rejected', $now->subMinutes(15), [['code' => 'schema_version_mismatch', 'field' => 'schema_version'], ['code' => 'unknown_field', 'field' => 'humidity']]);
    overviewOutcome($online, 'duplicate', $now->subMinutes(20));
    overviewOutcome($online, 'persisted', $now->subHours(30));

    $response = $this->actingAs($owner)->getJson($base)->assertOk();

    expect($response->json('data.devices'))->toMatchArray([
        'total' => 3, 'online' => 1, 'offline' => 2, 'unstable' => 0, 'pending_approval' => 1, 'unassigned' => 1,
    ])
        ->and(array_column($response->json('data.devices.attention'), 'id'))->toBe([$pending->getKey(), $quiet->getKey()])
        ->and($response->json('data.devices.attention.0.reason'))->toBe('pending_approval')
        ->and($response->json('data.alerts'))->toMatchArray([
            'active' => 2,
            'active_unacknowledged' => 1,
            'active_by_severity' => ['critical' => 1, 'major' => 1, 'minor' => 0, 'warning' => 0],
            'opened_in_window' => 2,
            'top_rules' => [['id' => $rule->getKey(), 'name' => 'Overheat', 'opened' => 2]],
        ])
        ->and(array_column($response->json('data.alerts.unacknowledged'), 'severity'))->toBe(['critical'])
        ->and($response->json('data.alerts.unacknowledged.0'))->toMatchArray(['rule_name' => 'Overheat', 'device_name' => $online->getAttribute('name')])
        ->and($response->json('data.ingest.totals'))->toMatchArray(['persisted' => 2, 'rejected' => 2, 'duplicate' => 1, 'skipped' => 0])
        ->and($response->json('data.ingest.top_reasons'))->toBe([
            ['code' => 'schema_version_mismatch', 'count' => 2],
            ['code' => 'unknown_field', 'count' => 1],
        ]);

    $buckets = $response->json('data.ingest.buckets');
    expect($buckets)->toHaveCount(24)
        ->and(end($buckets))->toMatchArray(['persisted' => 1, 'rejected' => 2, 'other' => 1])
        ->and($buckets[22])->toMatchArray(['persisted' => 1, 'rejected' => 0, 'other' => 0]);
});

it('shows pipeline and webhook health to tenant owners and project admins or engineers', function (?string $role): void {
    ['owner' => $owner, 'tenant' => $tenant, 'project' => $project, 'base' => $base] = overviewFixture();
    $viewer = $role === null ? $owner : overviewMember($tenant, $project, $role);
    $endpoint = WebhookEndpoint::factory()->for($project)->create(['name' => 'Pager']);
    $eventId = (string) Str::uuid();
    OutboxEvent::query()->insert([
        'id' => $eventId, 'project_id' => $project->getKey(), 'event_type' => 'alert.opened', 'aggregate_id' => (string) Str::uuid(),
        'idempotency_key' => 'k-1', 'payload' => '{}', 'created_at' => now()->subMinute(),
    ]);
    $event = OutboxEvent::query()->findOrFail($eventId);
    WebhookDelivery::factory()->forEvent($event, $endpoint)->create(['status' => 'exhausted', 'attempts' => 8, 'last_error' => 'http_500']);

    $this->actingAs($viewer)->getJson($base)
        ->assertOk()
        ->assertJsonPath('data.pipeline.outbox_pending', 1)
        ->assertJsonPath('data.pipeline.deliveries.exhausted', 1)
        ->assertJsonPath('data.pipeline.failing_endpoints.0.name', 'Pager')
        ->assertJsonPath('data.pipeline.failing_endpoints.0.last_error', 'http_500');
})->with(['tenant owner' => [null], 'admin' => ['admin'], 'engineer' => ['engineer']]);

it('hides pipeline health from operators and viewers', function (string $role): void {
    ['tenant' => $tenant, 'project' => $project, 'base' => $base] = overviewFixture();

    $this->actingAs(overviewMember($tenant, $project, $role))->getJson($base)
        ->assertOk()
        ->assertJsonPath('data.pipeline', null)
        ->assertJsonPath('data.devices.total', 0);
})->with(['operator', 'viewer']);

it('never counts data of other projects', function (): void {
    ['owner' => $owner, 'tenant' => $tenant, 'base' => $base] = overviewFixture();
    $otherProject = Project::factory()->for($tenant)->create();
    $foreignDevice = Device::factory()->for($otherProject)->create(['connection_state' => 'online']);
    overviewOutcome($foreignDevice, 'rejected', now()->toImmutable(), [['code' => 'invalid_payload', 'field' => null]]);

    $this->actingAs($owner)->getJson($base)
        ->assertOk()
        ->assertJsonPath('data.devices.total', 0)
        ->assertJsonPath('data.ingest.totals.rejected', 0)
        ->assertJsonPath('data.ingest.top_reasons', []);
});

it('hides the project from users of another tenant', function (): void {
    ['base' => $base] = overviewFixture();
    $outsider = overviewFixture();

    $this->actingAs($outsider['owner'])->getJson($base)->assertNotFound();
});

it('requires a member of the project', function (): void {
    ['tenant' => $tenant, 'base' => $base] = overviewFixture();
    $stranger = User::factory()->create();
    $tenant->users()->attach($stranger, ['role' => 'member']);

    $this->actingAs($stranger)->getJson($base)->assertForbidden();
});
