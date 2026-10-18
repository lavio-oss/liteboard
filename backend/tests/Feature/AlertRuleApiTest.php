<?php

use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\Alert\Alert;
use Modules\Admin\Models\Alert\OutboxEvent;
use Modules\Admin\Models\AlertRule\AlertRule;
use Modules\Admin\Models\AlertRule\AlertRuleRevision;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Admin\Models\DeviceProfile\DeviceProfileVersion;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

/** @return array{owner: User, tenant: Tenant, project: Project, profile: DeviceProfile, base: string} */
function alertRuleFixture(): array
{
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);
    $project = Project::factory()->for($tenant)->create();
    $profile = DeviceProfile::factory()->for($project)->create();
    $definition = DeviceProfileVersion::factory()->make()->definition;
    $definition['fields']['mode'] = ['type' => 'string', 'unit' => 'state', 'required' => false];
    DeviceProfileVersion::factory()->forProfile($profile)->create(['definition' => $definition]);

    return [
        'owner' => $owner,
        'tenant' => $tenant,
        'project' => $project,
        'profile' => $profile,
        'base' => "/api/tenants/$tenant->id/projects/$project->id/alert-rules",
    ];
}

/** @return array<string, mixed> */
function alertRuleDefinitionPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'field' => 'temperature',
        'trigger' => ['operator' => 'gt', 'value' => 80, 'duration_seconds' => 30],
        'clear' => ['operator' => 'lte', 'value' => 75, 'duration_seconds' => 30],
        'max_sample_gap_seconds' => 15,
        'severity' => 'critical',
    ], $overrides);
}

function alertRuleProjectMember(Tenant $tenant, Project $project, string $role): User
{
    $member = User::factory()->create();
    $tenant->users()->attach($member, ['role' => 'member']);
    $project->users()->attach($member, ['role' => $role]);

    return $member;
}

it('creates a rule with revision 1 and lists and shows it', function (): void {
    ['owner' => $owner, 'profile' => $profile, 'project' => $project, 'base' => $base] = alertRuleFixture();

    $created = $this->actingAs($owner)->postJson($base, [
        'code' => 'high_temperature',
        'name' => 'High temperature',
        'device_profile_id' => $profile->getKey(),
        'definition' => alertRuleDefinitionPayload(['trigger' => ['value' => '80.5']]),
    ])
        ->assertCreated()
        ->assertJsonPath('data.project_id', $project->getKey())
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.current_revision.revision', 1)
        ->assertJsonPath('data.current_revision.definition.trigger.value', 80.5)
        ->assertJsonPath('data.current_revision.definition.max_sample_gap_seconds', 15);

    $ruleId = (string) $created->json('data.id');
    expect(AlertRuleRevision::query()->where('alert_rule_id', $ruleId)->value('created_by'))->toBe($owner->getKey());

    $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($owner)->getJson("$base/$ruleId")
        ->assertOk()
        ->assertJsonCount(1, 'data.revisions');
});

it('rejects definitions that the evaluator could not run deterministically', function (array $overrides, string $errorKey): void {
    ['owner' => $owner, 'profile' => $profile, 'base' => $base] = alertRuleFixture();

    $this->actingAs($owner)->postJson($base, [
        'code' => 'rule',
        'name' => 'Rule',
        'device_profile_id' => $profile->getKey(),
        'definition' => alertRuleDefinitionPayload($overrides),
    ])->assertUnprocessable()->assertJsonValidationErrors($errorKey);
})->with([
    'unknown field' => [['field' => 'pressure'], 'definition.field'],
    'string field' => [['field' => 'mode'], 'definition.field'],
    'clear in the same direction' => [['clear' => ['operator' => 'gte', 'value' => 90]], 'definition.clear.operator'],
    'overlapping clear range' => [['clear' => ['value' => 81]], 'definition.clear.value'],
    'inclusive ranges touching' => [['trigger' => ['operator' => 'gte'], 'clear' => ['value' => 80]], 'definition.clear.value'],
    'trigger duration too short' => [['trigger' => ['duration_seconds' => 4]], 'definition.trigger.duration_seconds'],
    'clear duration too long' => [['clear' => ['duration_seconds' => 3601]], 'definition.clear.duration_seconds'],
    'gap too short' => [['max_sample_gap_seconds' => 4], 'definition.max_sample_gap_seconds'],
    'gap too long' => [['trigger' => ['duration_seconds' => 900], 'max_sample_gap_seconds' => 601], 'definition.max_sample_gap_seconds'],
    'gap longer than trigger duration' => [['max_sample_gap_seconds' => 31], 'definition.max_sample_gap_seconds'],
    'unsupported operator' => [['trigger' => ['operator' => 'eq']], 'definition.trigger.operator'],
    'unknown severity' => [['severity' => 'fatal'], 'definition.severity'],
]);

it('accepts a downward rule whose clear range sits above the trigger', function (): void {
    ['owner' => $owner, 'profile' => $profile, 'base' => $base] = alertRuleFixture();

    $this->actingAs($owner)->postJson($base, [
        'code' => 'low_rpm',
        'name' => 'Low rpm',
        'device_profile_id' => $profile->getKey(),
        'definition' => alertRuleDefinitionPayload([
            'field' => 'rpm',
            'trigger' => ['operator' => 'lt', 'value' => 100],
            'clear' => ['operator' => 'gte', 'value' => 100],
        ]),
    ])->assertCreated();
});

it('rejects a profile from another project, duplicate codes and server-owned fields', function (): void {
    ['owner' => $owner, 'profile' => $profile, 'tenant' => $tenant, 'base' => $base] = alertRuleFixture();
    $foreignProfile = DeviceProfile::factory()->for(Project::factory()->for($tenant))->create();
    $payload = [
        'code' => 'high_temperature',
        'name' => 'High temperature',
        'device_profile_id' => $profile->getKey(),
        'definition' => alertRuleDefinitionPayload(),
    ];

    $this->actingAs($owner)->postJson($base, [...$payload, 'device_profile_id' => $foreignProfile->getKey()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('device_profile_id');
    $this->actingAs($owner)->postJson($base, [...$payload, 'project_id' => $foreignProfile->project_id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('project_id');
    $this->actingAs($owner)->postJson($base, $payload)->assertCreated();
    $this->actingAs($owner)->postJson($base, $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});

it('appends immutable revisions and refuses new revisions after archive', function (): void {
    ['owner' => $owner, 'profile' => $profile, 'base' => $base] = alertRuleFixture();
    $ruleId = (string) $this->actingAs($owner)->postJson($base, [
        'code' => 'high_temperature',
        'name' => 'High temperature',
        'device_profile_id' => $profile->getKey(),
        'definition' => alertRuleDefinitionPayload(),
    ])->json('data.id');

    $this->actingAs($owner)->postJson("$base/$ruleId/revisions", [
        'definition' => alertRuleDefinitionPayload(['trigger' => ['value' => 90], 'clear' => ['value' => 85]]),
    ])
        ->assertCreated()
        ->assertJsonPath('data.current_revision.revision', 2)
        ->assertJsonPath('data.current_revision.definition.trigger.value', 90)
        ->assertJsonCount(2, 'data.revisions');

    $first = AlertRuleRevision::query()->where('alert_rule_id', $ruleId)->where('revision', 1)->firstOrFail();
    expect(fn () => $first->forceFill(['revision' => 9])->save())->toThrow(LogicException::class);

    $this->actingAs($owner)->postJson("$base/$ruleId/archive")
        ->assertOk()
        ->assertJsonPath('data.status', 'archived');
    $this->actingAs($owner)->postJson("$base/$ruleId/revisions", ['definition' => alertRuleDefinitionPayload()])
        ->assertConflict();
});

it('archive clears active alerts once with history and outbox and drops evaluation state', function (): void {
    ['owner' => $owner, 'profile' => $profile, 'project' => $project, 'base' => $base] = alertRuleFixture();
    $rule = AlertRule::factory()->forProfile($profile)->withRevision()->create();
    $device = Device::factory()->for($project)->create();
    $active = Alert::factory()->forRuleAndDevice($rule->refresh(), $device)->create();
    $cleared = Alert::factory()->forRuleAndDevice($rule, $device)->cleared()->create();
    DB::table('rule_evaluation_states')->insert([
        'device_id' => $device->getKey(),
        'alert_rule_id' => $rule->getKey(),
        'project_id' => $project->getKey(),
        'alert_rule_revision_id' => $rule->current_revision_id,
        'updated_at' => now(),
    ]);

    $this->actingAs($owner)->postJson("$base/{$rule->getKey()}/archive")->assertOk();
    $this->actingAs($owner)->postJson("$base/{$rule->getKey()}/archive")->assertOk();

    expect($active->refresh()->clear_reason?->value)->toBe('rule_archived')
        ->and($cleared->refresh()->clear_reason?->value)->toBe('condition_cleared')
        ->and(DB::table('rule_evaluation_states')->count())->toBe(0)
        ->and($active->events()->pluck('type')->map->value->all())->toBe(['cleared']);
    $outbox = OutboxEvent::query()->get();
    expect($outbox)->toHaveCount(1)
        ->and($outbox[0]->event_type)->toBe('alert.cleared')
        ->and($outbox[0]->idempotency_key)->toBe("alert:{$active->getKey()}:cleared")
        ->and($outbox[0]->payload['tenant_id'])->toBe($project->tenant_id)
        ->and($outbox[0]->payload['alert']['clear_reason'])->toBe('rule_archived')
        ->and(array_keys($outbox[0]->payload['alert']))->toBe([
            'id', 'rule_id', 'rule_code', 'rule_revision', 'severity', 'device_id', 'equipment_asset_id',
            'opened_at', 'cleared_at', 'clear_reason', 'acknowledged_at',
        ]);
});

it('lets viewers and operators read but not write rules', function (string $role): void {
    ['tenant' => $tenant, 'project' => $project, 'profile' => $profile, 'base' => $base] = alertRuleFixture();
    $member = alertRuleProjectMember($tenant, $project, $role);
    $rule = AlertRule::factory()->forProfile($profile)->withRevision()->create();

    $this->actingAs($member)->getJson($base)->assertOk();
    $this->actingAs($member)->getJson("$base/{$rule->getKey()}")->assertOk();
    $this->actingAs($member)->postJson($base, [
        'code' => 'x', 'name' => 'Xx', 'device_profile_id' => $profile->getKey(), 'definition' => alertRuleDefinitionPayload(),
    ])->assertForbidden();
    $this->actingAs($member)->postJson("$base/{$rule->getKey()}/revisions", ['definition' => alertRuleDefinitionPayload()])
        ->assertForbidden();
    $this->actingAs($member)->postJson("$base/{$rule->getKey()}/archive")->assertForbidden();
})->with(['viewer', 'operator']);

it('lets project engineers write rules', function (): void {
    ['tenant' => $tenant, 'project' => $project, 'profile' => $profile, 'base' => $base] = alertRuleFixture();
    $engineer = alertRuleProjectMember($tenant, $project, 'engineer');

    $this->actingAs($engineer)->postJson($base, [
        'code' => 'x', 'name' => 'Xx', 'device_profile_id' => $profile->getKey(), 'definition' => alertRuleDefinitionPayload(),
    ])->assertCreated();
});

it('hides rules from other tenants and other projects', function (): void {
    ['profile' => $profile, 'tenant' => $tenant, 'project' => $project] = alertRuleFixture();
    $rule = AlertRule::factory()->forProfile($profile)->withRevision()->create();
    $outsider = alertRuleFixture();
    $otherProject = Project::factory()->for($tenant)->create();
    $tenantOwner = $tenant->users()->wherePivot('role', 'owner')->firstOrFail();

    $this->actingAs($outsider['owner'])
        ->getJson("/api/tenants/$tenant->id/projects/$project->id/alert-rules/{$rule->getKey()}")
        ->assertNotFound();
    $this->actingAs($tenantOwner)
        ->getJson("/api/tenants/$tenant->id/projects/$otherProject->id/alert-rules/{$rule->getKey()}")
        ->assertNotFound();
});

it('requires the write token ability to create rules', function (): void {
    ['owner' => $owner, 'profile' => $profile, 'base' => $base] = alertRuleFixture();
    $token = $owner->createToken('reader', ['control-plane:read'])->plainTextToken;

    $this->withToken($token)->getJson($base)->assertOk();
    $this->withToken($token)->postJson($base, [
        'code' => 'x', 'name' => 'Xx', 'device_profile_id' => $profile->getKey(), 'definition' => alertRuleDefinitionPayload(),
    ])->assertForbidden();
});
