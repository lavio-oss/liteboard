<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Masterdata\Enums\Asset\AssetType;
use Modules\Masterdata\Models\Asset\Asset;
use Modules\Masterdata\Models\EntityRelation\EntityRelation;
use Modules\Masterdata\Models\Project\Project;

it('rejects unauthenticated asset access', function (): void {
    $this->getJson('/api/tenants/1/projects/1/assets')
        ->assertUnauthorized();
});

it('creates a tenant-scoped multi-level asset hierarchy', function (): void {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);
    $project = Project::factory()->for($tenant)->create();

    $siteResponse = $this->actingAs($owner)->postJson(
        '/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/assets',
        [
            'name' => 'Site Hanoi',
            'code' => 'site-hanoi',
            'asset_type' => AssetType::Site->value,
            'location' => 'Hanoi',
        ],
    );

    $siteResponse
        ->assertCreated()
        ->assertJsonPath('data.project_id', $project->id)
        ->assertJsonPath('data.parent_asset_id', null)
        ->assertJsonPath('data.asset_type', AssetType::Site->value);

    $site = Asset::query()->where('code', 'site-hanoi')->firstOrFail();

    $factoryResponse = $this->actingAs($owner)->postJson(
        '/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/assets',
        [
            'name' => 'Factory Hanoi',
            'code' => 'factory-hanoi',
            'asset_type' => AssetType::Factory->value,
            'parent_asset_id' => $site->getKey(),
        ],
    );

    $factoryResponse
        ->assertCreated()
        ->assertJsonPath('data.parent_asset_id', $site->getKey())
        ->assertJsonPath('data.asset_type', AssetType::Factory->value);

    $factory = Asset::query()->where('code', 'factory-hanoi')->firstOrFail();

    foreach ([AssetType::Area, AssetType::ProductionLine, AssetType::Station, AssetType::Equipment] as $assetType) {
        $this->actingAs($owner)
            ->postJson(
                '/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/assets',
                [
                    'name' => $assetType->name,
                    'code' => strtolower($assetType->name).'-1',
                    'asset_type' => $assetType->value,
                    'parent_asset_id' => $factory->getKey(),
                ],
            )
            ->assertCreated()
            ->assertJsonPath('data.asset_type', $assetType->value)
            ->assertJsonPath('data.parent_asset_id', $factory->getKey());
    }

    expect(Asset::query()->where('project_id', $project->getKey())->count())->toBe(6);
    expect(EntityRelation::query()
        ->where('project_id', $project->getKey())
        ->where('relation_type', EntityRelation::RELATION_TYPE_CONTAINS)
        ->count())->toBe(5);
    expect(Schema::hasColumn('assets', 'parent_asset_id'))->toBeFalse();
});

it('allows members to read but not mutate assets', function (): void {
    $member = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $asset = Asset::factory()->for($project)->create();
    $tenant->users()->attach($member, ['role' => 'member']);
    $project->users()->attach($member, ['role' => 'viewer']);

    $this->actingAs($member)
        ->getJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/assets')
        ->assertOk()
        ->assertJsonPath('data.0.id', $asset->getKey());

    $this->actingAs($member)
        ->postJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/assets', [
            'name' => 'Forbidden Asset',
            'code' => 'forbidden-asset',
            'asset_type' => AssetType::Area->value,
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->patchJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/assets/'.$asset->id, [
            'name' => 'Forbidden Update',
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->deleteJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/assets/'.$asset->id)
        ->assertForbidden();
});

it('allows project admins to create, update and delete assets', function (): void {
    $admin = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $tenant->users()->attach($admin, ['role' => 'member']);
    $project->users()->attach($admin, ['role' => 'admin']);

    $createResponse = $this->actingAs($admin)->postJson(
        '/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/assets',
        [
            'name' => 'Admin Asset',
            'code' => 'admin-asset',
            'asset_type' => AssetType::Area->value,
        ],
    );

    $asset = Asset::query()->where('code', 'admin-asset')->firstOrFail();

    $createResponse->assertCreated();

    $this->actingAs($admin)
        ->patchJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/assets/'.$asset->id, [
            'name' => 'Updated Admin Asset',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated Admin Asset');

    $this->actingAs($admin)
        ->deleteJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/assets/'.$asset->id)
        ->assertNoContent();

    $this->assertDatabaseMissing('assets', ['id' => $asset->id]);
});

it('hides assets from another tenant and rejects cross-project parents', function (): void {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $otherProject = Project::factory()->for($otherTenant)->create();
    $asset = Asset::factory()->for($project)->create();
    $otherAsset = Asset::factory()->for($otherProject)->create();
    $tenant->users()->attach($user, ['role' => 'member']);
    $project->users()->attach($user, ['role' => 'engineer']);

    $this->actingAs($user)
        ->getJson('/api/tenants/'.$otherTenant->id.'/projects')
        ->assertNotFound();

    $this->actingAs($user)
        ->getJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/assets/'.$otherAsset->id)
        ->assertNotFound();

    $this->actingAs($user)
        ->postJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/assets', [
            'name' => 'Cross Project Child',
            'code' => 'cross-project-child',
            'asset_type' => AssetType::Area->value,
            'parent_asset_id' => $otherAsset->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['parent_asset_id']);

    expect($asset->project_id)->toBe($project->id);
});

it('rejects invalid asset input and duplicate codes within a type', function (): void {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);
    $project = Project::factory()->for($tenant)->create();
    Asset::factory()->for($project)->create([
        'asset_type' => AssetType::Factory->value,
        'code' => 'duplicate-code',
    ]);

    $this->actingAs($owner)
        ->postJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/assets', [
            'name' => 'Invalid Asset',
            'code' => 'invalid-asset',
            'asset_type' => 'unknown',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['asset_type']);

    $this->actingAs($owner)
        ->postJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/assets', [
            'name' => 'Duplicate Asset',
            'code' => 'duplicate-code',
            'asset_type' => AssetType::Factory->value,
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code', 'tenant_id', 'project_id']);
});

it('supports asset search, type filtering, parent filtering and roots', function (): void {
    $member = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $tenant->users()->attach($member, ['role' => 'member']);
    $project->users()->attach($member, ['role' => 'viewer']);
    $root = Asset::factory()->for($project)->create([
        'name' => 'Alpha Factory',
        'code' => 'alpha-factory',
        'asset_type' => AssetType::Factory->value,
    ]);
    Asset::factory()->for($project)->withParent($root)->create([
        'name' => 'Alpha Line',
        'code' => 'alpha-line',
        'asset_type' => AssetType::ProductionLine->value,
    ]);
    Asset::factory()->for($project)->create([
        'name' => 'Beta Area',
        'code' => 'beta-area',
        'asset_type' => AssetType::Area->value,
    ]);

    $this->actingAs($member)
        ->getJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/assets?search=Alpha&asset_type=factory&root_only=true&per_page=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Alpha Factory')
        ->assertJsonPath('meta.per_page', 1);

    $this->actingAs($member)
        ->getJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/assets?parent_asset_id='.$root->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Alpha Line');

    $this->actingAs($member)
        ->getJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/assets?parent_asset_id='.$root->id.'&root_only=true')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['root_only']);
});

it('rejects self-parenting and hierarchy cycles', function (): void {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);
    $project = Project::factory()->for($tenant)->create();
    $parent = Asset::factory()->for($project)->create([
        'asset_type' => AssetType::Factory->value,
    ]);
    $child = Asset::factory()->for($project)->withParent($parent)->create([
        'asset_type' => AssetType::Area->value,
    ]);

    $this->actingAs($owner)
        ->patchJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/assets/'.$parent->id, [
            'parent_asset_id' => $parent->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['parent_asset_id']);

    $this->actingAs($owner)
        ->patchJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/assets/'.$parent->id, [
            'parent_asset_id' => $child->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['parent_asset_id']);
});

it('replaces and removes an asset parent through contains relations', function (): void {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);
    $project = Project::factory()->for($tenant)->create();
    $firstParent = Asset::factory()->for($project)->create();
    $secondParent = Asset::factory()->for($project)->create();
    $child = Asset::factory()->for($project)->withParent($firstParent)->create();
    $endpoint = '/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/assets/'.$child->id;

    $this->actingAs($owner)
        ->patchJson($endpoint, ['parent_asset_id' => $secondParent->id])
        ->assertOk()
        ->assertJsonPath('data.parent_asset_id', $secondParent->id);

    expect(EntityRelation::query()
        ->where('project_id', $project->id)
        ->where('target_id', $child->id)
        ->where('relation_type', EntityRelation::RELATION_TYPE_CONTAINS)
        ->count())->toBe(1);
    $this->assertDatabaseHas('entity_relations', [
        'source_id' => $secondParent->id,
        'target_id' => $child->id,
        'relation_type' => EntityRelation::RELATION_TYPE_CONTAINS,
    ]);

    $this->actingAs($owner)
        ->patchJson($endpoint, ['parent_asset_id' => null])
        ->assertOk()
        ->assertJsonPath('data.parent_asset_id', null);

    $this->assertDatabaseMissing('entity_relations', [
        'target_id' => $child->id,
        'relation_type' => EntityRelation::RELATION_TYPE_CONTAINS,
    ]);
});

it('keeps child assets when a parent asset is deleted', function (): void {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);
    $project = Project::factory()->for($tenant)->create();
    $parent = Asset::factory()->for($project)->create();
    $child = Asset::factory()->for($project)->withParent($parent)->create();

    $this->actingAs($owner)
        ->deleteJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/assets/'.$parent->id)
        ->assertNoContent();

    $this->assertDatabaseHas('assets', [
        'id' => $child->id,
    ]);
    $this->assertDatabaseMissing('entity_relations', [
        'target_id' => $child->id,
        'relation_type' => EntityRelation::RELATION_TYPE_CONTAINS,
    ]);
});

it('cascades project and tenant deletion through assets', function (): void {
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $asset = Asset::factory()->for($project)->create();

    $tenant->delete();

    $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    $this->assertDatabaseMissing('assets', ['id' => $asset->id]);
});

it('does not expose the removed factory and production line endpoints', function (): void {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);
    $project = Project::factory()->for($tenant)->create();

    $this->actingAs($owner)
        ->getJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/factories')
        ->assertNotFound();

    $this->actingAs($owner)
        ->getJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/production-lines')
        ->assertNotFound();
});

it('cascades project and tenant key updates through asset relations', function (): void {
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $parent = Asset::factory()->for($project)->create();
    $child = Asset::factory()->for($project)->withParent($parent)->create();
    $newTenantId = $tenant->id + 10000;
    $newProjectId = $project->id + 10000;

    DB::table('tenants')->where('id', $tenant->id)->update(['id' => $newTenantId]);
    DB::table('projects')->where('id', $project->id)->update(['id' => $newProjectId]);

    $this->assertDatabaseHas('projects', ['id' => $newProjectId, 'tenant_id' => $newTenantId]);
    $this->assertDatabaseHas('assets', ['id' => $parent->id, 'project_id' => $newProjectId]);
    $this->assertDatabaseHas('entity_relations', [
        'project_id' => $newProjectId,
        'source_id' => $parent->id,
        'target_id' => $child->id,
        'relation_type' => EntityRelation::RELATION_TYPE_CONTAINS,
    ]);
});
