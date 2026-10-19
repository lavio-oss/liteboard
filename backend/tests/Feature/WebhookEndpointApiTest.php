<?php

use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\AlertRule\AlertRule;
use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Models\Webhook\WebhookEndpoint;
use Modules\Admin\Services\Webhook\HostResolver;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

/** @return array{owner: User, tenant: Tenant, project: Project, base: string} */
function webhookEndpointFixture(): array
{
    app()->instance(HostResolver::class, new class implements HostResolver
    {
        public function resolve(string $host): array
        {
            return ['hooks.example.com' => ['93.184.216.34'], 'internal.example.com' => ['10.0.0.5']][$host] ?? [];
        }
    });
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);
    $project = Project::factory()->for($tenant)->create();

    return [
        'owner' => $owner,
        'tenant' => $tenant,
        'project' => $project,
        'base' => "/api/tenants/$tenant->id/projects/$project->id/webhook-endpoints",
    ];
}

function webhookProjectMember(Tenant $tenant, Project $project, string $role): User
{
    $member = User::factory()->create();
    $tenant->users()->attach($member, ['role' => 'member']);
    $project->users()->attach($member, ['role' => $role]);

    return $member;
}

/** @return array<string, mixed> */
function webhookEndpointPayload(array $overrides = []): array
{
    return [
        'name' => 'EAMO',
        'url' => 'https://hooks.example.com/liteboard',
        'event_types' => ['alert.opened', 'alert.cleared'],
        ...$overrides,
    ];
}

it('returns the secret only on create and keeps it encrypted at rest', function (): void {
    ['owner' => $owner, 'project' => $project, 'base' => $base] = webhookEndpointFixture();

    $created = $this->actingAs($owner)->postJson($base, webhookEndpointPayload())
        ->assertCreated()
        ->assertJsonPath('data.project_id', $project->getKey())
        ->assertJsonPath('data.enabled', true)
        ->assertJsonMissingPath('data.secret');
    $secret = (string) $created->json('secret');
    $id = (string) $created->json('data.id');

    expect($secret)->toStartWith('whsec_')
        ->and(DB::table('webhook_endpoints')->where('id', $id)->value('secret'))->not->toContain($secret)
        ->and(WebhookEndpoint::query()->findOrFail($id)->secret)->toBe($secret);
    $listed = $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonCount(1, 'data');
    expect($listed->getContent())->not->toContain($secret);
});

it('updates endpoints and revalidates the destination', function (): void {
    ['owner' => $owner, 'project' => $project, 'base' => $base] = webhookEndpointFixture();
    $endpoint = WebhookEndpoint::factory()->for($project)->create();

    $this->actingAs($owner)->patchJson("$base/{$endpoint->getKey()}", ['enabled' => false, 'event_types' => ['alert.opened']])
        ->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.event_types', ['alert.opened']);
    $this->actingAs($owner)->patchJson("$base/{$endpoint->getKey()}", ['url' => 'https://internal.example.com/x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');
});

it('stores the explicit rule list limited to rules of the same project', function (): void {
    ['owner' => $owner, 'tenant' => $tenant, 'project' => $project, 'base' => $base] = webhookEndpointFixture();
    $profile = DeviceProfile::factory()->for($project)->create();
    $rule = AlertRule::factory()->forProfile($profile)->create();
    $foreignRule = AlertRule::factory()->forProfile(DeviceProfile::factory()->for(Project::factory()->for($tenant))->create())->create();

    $id = (string) $this->actingAs($owner)->postJson($base, webhookEndpointPayload())
        ->assertCreated()
        ->assertJsonPath('data.alert_rule_ids', [])
        ->json('data.id');

    $this->actingAs($owner)->patchJson("$base/$id", ['alert_rule_ids' => [$rule->getKey()]])
        ->assertOk()
        ->assertJsonPath('data.alert_rule_ids', [$rule->getKey()]);
    $this->actingAs($owner)->patchJson("$base/$id", ['alert_rule_ids' => [$foreignRule->getKey()]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('alert_rule_ids.0');
    $this->actingAs($owner)->patchJson("$base/$id", ['alert_rule_ids' => [$rule->getKey(), $rule->getKey()]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('alert_rule_ids.0');
    $this->actingAs($owner)->patchJson("$base/$id", ['alert_rule_ids' => []])
        ->assertOk()
        ->assertJsonPath('data.alert_rule_ids', []);
    $this->actingAs($owner)->patchJson("$base/$id", ['alert_rule_ids' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('alert_rule_ids');
});

it('rejects unsafe or malformed endpoint definitions', function (array $overrides, string $errorKey): void {
    ['owner' => $owner, 'base' => $base] = webhookEndpointFixture();

    $this->actingAs($owner)->postJson($base, webhookEndpointPayload($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorKey);
})->with([
    'plain http' => [['url' => 'http://hooks.example.com/x'], 'url'],
    'private dns' => [['url' => 'https://internal.example.com/x'], 'url'],
    'loopback' => [['url' => 'https://127.0.0.1/x'], 'url'],
    'metadata' => [['url' => 'https://169.254.169.254/latest/meta-data'], 'url'],
    'ipv6 loopback' => [['url' => 'https://[::1]/x'], 'url'],
    'ipv6 unique local' => [['url' => 'https://[fc00::1]/x'], 'url'],
    'credentials' => [['url' => 'https://user:pw@hooks.example.com/x'], 'url'],
    'unknown event' => [['event_types' => ['device.deleted']], 'event_types.0'],
    'duplicate event' => [['event_types' => ['alert.opened', 'alert.opened']], 'event_types.0'],
    'client secret' => [['secret' => 'mine'], 'secret'],
]);

it('rotates the secret for admins only', function (): void {
    ['tenant' => $tenant, 'project' => $project, 'base' => $base] = webhookEndpointFixture();
    $endpoint = WebhookEndpoint::factory()->for($project)->create();
    $old = $endpoint->secret;
    $admin = webhookProjectMember($tenant, $project, 'admin');

    $rotated = $this->actingAs($admin)->postJson("$base/{$endpoint->getKey()}/rotate-secret")
        ->assertOk()
        ->assertJsonPath('data.id', $endpoint->getKey());

    expect($rotated->json('secret'))->not->toBe($old)
        ->and($endpoint->refresh()->secret)->toBe($rotated->json('secret'))
        ->and($endpoint->secret_rotated_at)->not->toBeNull();
});

it('lets engineers view but not change endpoints or secrets', function (): void {
    ['tenant' => $tenant, 'project' => $project, 'base' => $base] = webhookEndpointFixture();
    $endpoint = WebhookEndpoint::factory()->for($project)->create();
    $engineer = webhookProjectMember($tenant, $project, 'engineer');

    $this->actingAs($engineer)->getJson($base)->assertOk();
    $this->actingAs($engineer)->getJson("$base/{$endpoint->getKey()}/deliveries")->assertOk();
    $this->actingAs($engineer)->postJson($base, webhookEndpointPayload())->assertForbidden();
    $this->actingAs($engineer)->patchJson("$base/{$endpoint->getKey()}", ['enabled' => false])->assertForbidden();
    $this->actingAs($engineer)->postJson("$base/{$endpoint->getKey()}/rotate-secret")->assertForbidden();
});

it('hides endpoints from operators and viewers', function (string $role): void {
    ['tenant' => $tenant, 'project' => $project, 'base' => $base] = webhookEndpointFixture();
    $member = webhookProjectMember($tenant, $project, $role);

    $this->actingAs($member)->getJson($base)->assertForbidden();
})->with(['viewer', 'operator']);

it('isolates endpoints across tenants and projects', function (): void {
    ['owner' => $owner, 'tenant' => $tenant, 'project' => $project] = webhookEndpointFixture();
    $endpoint = WebhookEndpoint::factory()->for($project)->create();
    $otherProject = Project::factory()->for($tenant)->create();
    $outsider = webhookEndpointFixture();

    $this->actingAs($owner)
        ->getJson("/api/tenants/$tenant->id/projects/$otherProject->id/webhook-endpoints/{$endpoint->getKey()}/deliveries")
        ->assertNotFound();
    $this->actingAs($outsider['owner'])
        ->postJson("/api/tenants/$tenant->id/projects/$project->id/webhook-endpoints/{$endpoint->getKey()}/rotate-secret")
        ->assertNotFound();
});
