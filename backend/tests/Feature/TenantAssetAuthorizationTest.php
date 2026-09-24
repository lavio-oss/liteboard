<?php

use Modules\Admin\Enums\Asset\AssetType;
use Modules\Admin\Models\Asset\Asset;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

it('lets tenant owners and admins list assets across their tenant projects', function (string $role): void {
    $tenant = Tenant::factory()->create();
    $firstProject = Project::factory()->for($tenant)->create();
    $secondProject = Project::factory()->for($tenant)->create();
    $otherTenant = Tenant::factory()->create();
    $otherProject = Project::factory()->for($otherTenant)->create();
    $firstAsset = Asset::factory()->for($firstProject)->create();
    $secondAsset = Asset::factory()->for($secondProject)->create();
    Asset::factory()->for($otherProject)->create();

    $user = User::factory()->create();
    $tenant->users()->attach($user, ['role' => $role]);

    $this->actingAs($user)
        ->getJson('/api/tenants/'.$tenant->getKey().'/assets')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonFragment(['id' => $firstAsset->getKey()])
        ->assertJsonFragment(['id' => $secondAsset->getKey()]);
})->with(['owner', 'admin']);

it('keeps tenant members within their assigned projects and denies tenant-wide asset access', function (): void {
    $member = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $assignedProject = Project::factory()->for($tenant)->create();
    $unassignedProject = Project::factory()->for($tenant)->create();
    $assignedAsset = Asset::factory()->for($assignedProject)->create();
    Asset::factory()->for($unassignedProject)->create();
    $tenant->users()->attach($member, ['role' => 'member']);
    $assignedProject->users()->attach($member, ['role' => 'viewer']);

    $this->actingAs($member)
        ->getJson('/api/tenants/'.$tenant->getKey().'/assets')
        ->assertForbidden();

    $this->actingAs($member)
        ->getJson('/api/tenants/'.$tenant->getKey().'/projects')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $assignedProject->getKey());

    $this->actingAs($member)
        ->getJson('/api/tenants/'.$tenant->getKey().'/projects/'.$assignedProject->getKey().'/assets')
        ->assertOk()
        ->assertJsonPath('data.0.id', $assignedAsset->getKey());

    $this->actingAs($member)
        ->getJson('/api/tenants/'.$tenant->getKey().'/projects/'.$unassignedProject->getKey().'/assets')
        ->assertForbidden();
});

it('does not expand a project admin or engineer role to tenant-wide asset access', function (string $role): void {
    $member = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    Asset::factory()->for($project)->create();
    $tenant->users()->attach($member, ['role' => 'member']);
    $project->users()->attach($member, ['role' => $role]);

    $this->actingAs($member)
        ->getJson('/api/tenants/'.$tenant->getKey().'/assets')
        ->assertForbidden();

    $this->actingAs($member)
        ->getJson('/api/tenants/'.$tenant->getKey().'/projects/'.$project->getKey().'/assets')
        ->assertOk();
})->with(['admin', 'engineer']);

it('rejects unauthenticated tenant-wide asset access', function (): void {
    $tenant = Tenant::factory()->create();

    $this->getJson('/api/tenants/'.$tenant->getKey().'/assets')
        ->assertUnauthorized();
});

it('allows platform admins tenant-wide asset reads while keeping the requested tenant scope', function (): void {
    $platformAdmin = User::factory()->create(['system_role' => 'platform_admin']);
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();
    $asset = Asset::factory()->for(Project::factory()->for($tenant))->create();
    Asset::factory()->for(Project::factory()->for($otherTenant))->create();

    $this->actingAs($platformAdmin)
        ->getJson('/api/tenants/'.$tenant->getKey().'/assets')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $asset->getKey());
});

it('filters and paginates tenant assets without accepting a project from another tenant', function (): void {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $otherProject = Project::factory()->for($tenant)->create();
    $otherTenant = Tenant::factory()->create();
    $foreignProject = Project::factory()->for($otherTenant)->create();
    $firstMatch = Asset::factory()->for($project)->create([
        'name' => 'Pump Alpha',
        'code' => 'pump-alpha',
        'asset_type' => AssetType::Equipment->value,
    ]);
    Asset::factory()->for($project)->create([
        'name' => 'Pump Beta',
        'code' => 'pump-beta',
        'asset_type' => AssetType::Equipment->value,
    ]);
    Asset::factory()->for($otherProject)->create([
        'name' => 'Pump Gamma',
        'code' => 'pump-gamma',
        'asset_type' => AssetType::Equipment->value,
    ]);
    Asset::factory()->for($project)->create([
        'name' => 'Pump Not Equipment',
        'code' => 'pump-not-equipment',
        'asset_type' => AssetType::Factory->value,
    ]);
    $tenant->users()->attach($owner, ['role' => 'owner']);

    $this->actingAs($owner)
        ->getJson('/api/tenants/'.$tenant->getKey().'/assets?search=Pump&asset_type=equipment&project_id='.$project->getKey().'&per_page=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $firstMatch->getKey())
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.per_page', 1);

    $this->actingAs($owner)
        ->getJson('/api/tenants/'.$tenant->getKey().'/assets?project_id='.$foreignProject->getKey())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['project_id']);

    $this->actingAs($owner)
        ->getJson('/api/tenants/'.$otherTenant->getKey().'/assets')
        ->assertNotFound();
});
