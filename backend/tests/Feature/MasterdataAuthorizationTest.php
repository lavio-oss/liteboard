<?php

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Masterdata\Models\Factory\Factory as FactoryModel;
use Modules\Masterdata\Models\ProductionLine\ProductionLine;
use Modules\Masterdata\Models\Project\Project;

it('rejects unauthenticated project access', function (): void {
    $this->getJson('/api/tenants/1/projects')
        ->assertUnauthorized();
});

it('creates the hierarchy and returns tenant-scoped resources', function (): void {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);

    $projectResponse = $this->actingAs($owner)->postJson('/api/tenants/'.$tenant->id.'/projects', [
        'name' => 'Project A',
        'description' => 'Main project',
        'status' => 'active',
    ]);

    $projectResponse
        ->assertCreated()
        ->assertJsonPath('data.name', 'Project A')
        ->assertJsonPath('data.tenant_id', $tenant->id);

    $project = Project::query()->where('name', 'Project A')->firstOrFail();

    $factoryResponse = $this->actingAs($owner)->postJson(
        '/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/factories',
        [
            'name' => 'Factory Hanoi',
            'code' => 'factory-hanoi',
            'location' => 'Hanoi',
        ],
    );

    $factoryResponse
        ->assertCreated()
        ->assertJsonPath('data.project_id', $project->id)
        ->assertJsonPath('data.code', 'factory-hanoi');

    $factory = FactoryModel::query()->where('code', 'factory-hanoi')->firstOrFail();

    $lineResponse = $this->actingAs($owner)->postJson(
        '/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/factories/'.$factory->id.'/production-lines',
        [
            'name' => 'Line 1',
            'code' => 'line-1',
        ],
    );

    $lineResponse
        ->assertCreated()
        ->assertJsonPath('data.factory_id', $factory->id)
        ->assertJsonPath('data.code', 'line-1');
});

it('allows members to read but not mutate masterdata', function (): void {
    $member = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $tenant->users()->attach($member, ['role' => 'member']);

    $this->actingAs($member)
        ->getJson('/api/tenants/'.$tenant->id.'/projects')
        ->assertOk()
        ->assertJsonPath('data.0.id', $project->id);

    $this->actingAs($member)
        ->postJson('/api/tenants/'.$tenant->id.'/projects', [
            'name' => 'Forbidden Project',
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->patchJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id, [
            'name' => 'Forbidden Update',
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->deleteJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id)
        ->assertForbidden();
});

it('allows admins to create and update masterdata', function (): void {
    $admin = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($admin, ['role' => 'admin']);

    $createResponse = $this->actingAs($admin)->postJson('/api/tenants/'.$tenant->id.'/projects', [
        'name' => 'Admin Project',
    ]);

    $createResponse->assertCreated();
    $project = Project::query()->where('name', 'Admin Project')->firstOrFail();

    $this->actingAs($admin)
        ->patchJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id, [
            'name' => 'Updated Admin Project',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated Admin Project');

    $this->actingAs($admin)
        ->deleteJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id)
        ->assertNoContent();
});

it('hides resources from another tenant and rejects mismatched parent bindings', function (): void {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $otherProject = Project::factory()->for($otherTenant)->create();
    $factory = FactoryModel::factory()->for($project)->create();
    $otherFactory = FactoryModel::factory()->for($otherProject)->create();
    $line = ProductionLine::factory()->for($factory, 'productionFactory')->create();
    $otherLine = ProductionLine::factory()->for($otherFactory, 'productionFactory')->create();
    $tenant->users()->attach($user, ['role' => 'member']);

    $this->actingAs($user)
        ->getJson('/api/tenants/'.$otherTenant->id.'/projects')
        ->assertNotFound();

    $this->actingAs($user)
        ->getJson('/api/tenants/'.$tenant->id.'/projects/'.$otherProject->id)
        ->assertNotFound();

    $this->actingAs($user)
        ->getJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/factories/'.$otherFactory->id)
        ->assertNotFound();

    $this->actingAs($user)
        ->getJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/factories/'.$factory->id.'/production-lines/'.$otherLine->id)
        ->assertNotFound();

    expect($line->factory_id)->toBe($factory->id);
});

it('rejects client supplied parent ids and duplicate child codes', function (): void {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);
    $project = Project::factory()->for($tenant)->create();
    $factory = FactoryModel::factory()->for($project)->create(['code' => 'factory-1']);
    ProductionLine::factory()->for($factory, 'productionFactory')->create(['code' => 'line-1']);

    $this->actingAs($owner)
        ->postJson('/api/tenants/'.$tenant->id.'/projects', [
            'name' => 'Invalid Parent',
            'tenant_id' => $tenant->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tenant_id']);

    $this->actingAs($owner)
        ->postJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/factories', [
            'name' => 'Duplicate Factory',
            'code' => 'factory-1',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    $this->actingAs($owner)
        ->postJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/factories/'.$factory->id.'/production-lines', [
            'name' => 'Duplicate Line',
            'code' => 'line-1',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

it('supports search and pagination within the authorized parent scope', function (): void {
    $member = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($member, ['role' => 'member']);
    Project::factory()->for($tenant)->create(['name' => 'Alpha Project']);
    Project::factory()->for($tenant)->create(['name' => 'Beta Project']);

    $this->actingAs($member)
        ->getJson('/api/tenants/'.$tenant->id.'/projects?search=Alpha&per_page=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Alpha Project')
        ->assertJsonPath('meta.per_page', 1);
});

it('cascades deletes from tenant through the complete hierarchy', function (): void {
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $factory = FactoryModel::factory()->for($project)->create();
    $line = ProductionLine::factory()->for($factory, 'productionFactory')->create();

    $tenant->delete();

    $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    $this->assertDatabaseMissing('factories', ['id' => $factory->id]);
    $this->assertDatabaseMissing('production_lines', ['id' => $line->id]);
});

it('cascades deletes from project and factory', function (): void {
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $factory = FactoryModel::factory()->for($project)->create();
    $line = ProductionLine::factory()->for($factory, 'productionFactory')->create();

    $project->delete();

    $this->assertDatabaseMissing('factories', ['id' => $factory->id]);
    $this->assertDatabaseMissing('production_lines', ['id' => $line->id]);

    $secondProject = Project::factory()->for($tenant)->create();
    $secondFactory = FactoryModel::factory()->for($secondProject)->create();
    $secondLine = ProductionLine::factory()->for($secondFactory, 'productionFactory')->create();

    $secondFactory->delete();

    $this->assertDatabaseMissing('production_lines', ['id' => $secondLine->id]);
});

it('cascades parent key updates through the hierarchy', function (): void {
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $factory = FactoryModel::factory()->for($project)->create();
    $line = ProductionLine::factory()->for($factory, 'productionFactory')->create();
    $newTenantId = $tenant->id + 10000;
    $newProjectId = $project->id + 10000;
    $newFactoryId = $factory->id + 10000;

    DB::table('tenants')->where('id', $tenant->id)->update(['id' => $newTenantId]);
    DB::table('projects')->where('id', $project->id)->update(['id' => $newProjectId]);
    DB::table('factories')->where('id', $factory->id)->update(['id' => $newFactoryId]);

    $this->assertDatabaseHas('projects', ['id' => $newProjectId, 'tenant_id' => $newTenantId]);
    $this->assertDatabaseHas('factories', ['id' => $newFactoryId, 'project_id' => $newProjectId]);
    $this->assertDatabaseHas('production_lines', ['id' => $line->id, 'factory_id' => $newFactoryId]);
});
