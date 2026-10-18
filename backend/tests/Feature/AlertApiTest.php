<?php

use Modules\Admin\Models\Alert\Alert;
use Modules\Admin\Models\Alert\OutboxEvent;
use Modules\Admin\Models\AlertRule\AlertRule;
use Modules\Admin\Models\Asset\Asset;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

/** @return array{owner: User, tenant: Tenant, project: Project, rule: AlertRule, device: Device, base: string} */
function alertFixture(): array
{
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);
    $project = Project::factory()->for($tenant)->create();
    $rule = AlertRule::factory()->forProfile(DeviceProfile::factory()->for($project)->create())->withRevision()->create()->refresh();
    $device = Device::factory()->for($project)->create();

    return [
        'owner' => $owner,
        'tenant' => $tenant,
        'project' => $project,
        'rule' => $rule,
        'device' => $device,
        'base' => "/api/tenants/$tenant->id/projects/$project->id/alerts",
    ];
}

function alertProjectMember(Tenant $tenant, Project $project, string $role): User
{
    $member = User::factory()->create();
    $tenant->users()->attach($member, ['role' => 'member']);
    $project->users()->attach($member, ['role' => $role]);

    return $member;
}

it('lists alerts newest first with state, acknowledgement, device and rule filters', function (): void {
    ['owner' => $owner, 'project' => $project, 'rule' => $rule, 'device' => $device, 'base' => $base] = alertFixture();
    $otherDevice = Device::factory()->for($project)->create();
    $active = Alert::factory()->forRuleAndDevice($rule, $device)->create(['opened_at' => now()->subMinute()]);
    $cleared = Alert::factory()->forRuleAndDevice($rule, $device)->cleared()->create([
        'opened_at' => now()->subHour(),
        'acknowledged_at' => now()->subMinutes(30),
    ]);
    $other = Alert::factory()->forRuleAndDevice($rule, $otherDevice)->create(['opened_at' => now()->subMinutes(2)]);

    $this->actingAs($owner)->getJson($base)
        ->assertOk()
        ->assertJsonPath('data.*.id', [$active->getKey(), $other->getKey(), $cleared->getKey()]);
    $this->actingAs($owner)->getJson("$base?state=active")->assertJsonCount(2, 'data');
    $this->actingAs($owner)->getJson("$base?state=cleared")->assertJsonPath('data.0.id', $cleared->getKey());
    $this->actingAs($owner)->getJson("$base?acknowledged=1")->assertJsonPath('data.*.id', [$cleared->getKey()]);
    $this->actingAs($owner)->getJson("$base?acknowledged=0")->assertJsonCount(2, 'data');
    $this->actingAs($owner)->getJson("$base?device_id={$otherDevice->getKey()}")->assertJsonPath('data.*.id', [$other->getKey()]);
    $this->actingAs($owner)->getJson("$base?alert_rule_id={$rule->getKey()}")->assertJsonCount(3, 'data');
    $this->actingAs($owner)->getJson("$base?state=open")->assertUnprocessable();

    $page = $this->actingAs($owner)->getJson("$base?limit=2")->assertJsonCount(2, 'data');
    $this->actingAs($owner)->getJson("$base?limit=2&cursor={$page->json('meta.next_cursor')}")
        ->assertJsonPath('data.*.id', [$cleared->getKey()]);
});

it('shows an alert with its equipment snapshot and ordered history', function (): void {
    ['owner' => $owner, 'project' => $project, 'rule' => $rule, 'base' => $base] = alertFixture();
    $equipment = Asset::factory()->for($project)->create(['asset_type' => 'equipment']);
    $device = Device::factory()->for($project)->forEquipment($equipment)->create();
    $alert = Alert::factory()->forRuleAndDevice($rule, $device)->create();

    $this->actingAs($owner)->postJson("$base/{$alert->getKey()}/acknowledge")->assertOk();

    $this->actingAs($owner)->getJson("$base/{$alert->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.equipment_asset_id', $equipment->getKey())
        ->assertJsonPath('data.severity', 'critical')
        ->assertJsonPath('data.events.0.type', 'acknowledged')
        ->assertJsonPath('data.events.0.actor_id', $owner->getKey());
});

it('acknowledges once, keeps the alert active and emits exactly one outbox event', function (): void {
    ['owner' => $owner, 'rule' => $rule, 'device' => $device, 'base' => $base] = alertFixture();
    $alert = Alert::factory()->forRuleAndDevice($rule, $device)->create();

    $first = $this->actingAs($owner)->postJson("$base/{$alert->getKey()}/acknowledge")
        ->assertOk()
        ->assertJsonPath('data.acknowledged_by', $owner->getKey())
        ->assertJsonPath('data.cleared_at', null)
        ->assertJsonPath('data.clear_reason', null);
    $this->travel(5)->minutes();
    $this->actingAs($owner)->postJson("$base/{$alert->getKey()}/acknowledge")
        ->assertOk()
        ->assertJsonPath('data.acknowledged_at', $first->json('data.acknowledged_at'));

    expect($alert->events()->count())->toBe(1);
    $outbox = OutboxEvent::query()->sole();
    expect($outbox->event_type)->toBe('alert.acknowledged')
        ->and($outbox->idempotency_key)->toBe("alert:{$alert->getKey()}:acknowledged")
        ->and($outbox->payload['alert']['acknowledged_at'])->toBe($first->json('data.acknowledged_at'))
        ->and($outbox->payload['alert']['cleared_at'])->toBeNull();
});

it('refuses client attempts to clear through acknowledge', function (): void {
    ['owner' => $owner, 'rule' => $rule, 'device' => $device, 'base' => $base] = alertFixture();
    $alert = Alert::factory()->forRuleAndDevice($rule, $device)->create();

    $this->actingAs($owner)->postJson("$base/{$alert->getKey()}/acknowledge", ['clear_reason' => 'condition_cleared'])
        ->assertUnprocessable();
    expect($alert->refresh()->cleared_at)->toBeNull();
});

it('only lets viewers and operators read alerts', function (string $role): void {
    ['tenant' => $tenant, 'project' => $project, 'rule' => $rule, 'device' => $device, 'base' => $base] = alertFixture();
    $alert = Alert::factory()->forRuleAndDevice($rule, $device)->create();
    $member = alertProjectMember($tenant, $project, $role);

    $this->actingAs($member)->getJson("$base/{$alert->getKey()}")->assertOk();
    $this->actingAs($member)->postJson("$base/{$alert->getKey()}/acknowledge")->assertForbidden();
})->with(['viewer', 'operator']);

it('lets project engineers acknowledge alerts', function (): void {
    ['tenant' => $tenant, 'project' => $project, 'rule' => $rule, 'device' => $device, 'base' => $base] = alertFixture();
    $alert = Alert::factory()->forRuleAndDevice($rule, $device)->create();
    $engineer = alertProjectMember($tenant, $project, 'engineer');

    $this->actingAs($engineer)->postJson("$base/{$alert->getKey()}/acknowledge")->assertOk();
});

it('hides alerts across tenants and projects', function (): void {
    ['owner' => $owner, 'tenant' => $tenant, 'project' => $project, 'rule' => $rule, 'device' => $device] = alertFixture();
    $alert = Alert::factory()->forRuleAndDevice($rule, $device)->create();
    $outsider = alertFixture();
    $otherProject = Project::factory()->for($tenant)->create();

    $this->actingAs($outsider['owner'])
        ->postJson("/api/tenants/$tenant->id/projects/$project->id/alerts/{$alert->getKey()}/acknowledge")
        ->assertNotFound();
    $this->actingAs($owner)
        ->getJson("/api/tenants/$tenant->id/projects/$otherProject->id/alerts/{$alert->getKey()}")
        ->assertNotFound();
    $this->actingAs($outsider['owner'])
        ->getJson($outsider['base'])
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
