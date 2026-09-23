<?php

use Modules\Admin\Enums\Asset\AssetType;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

it('lets a platform admin manage tenants without a tenant membership', function (): void {
    $platformAdmin = User::factory()->create(['system_role' => 'platform_admin']);
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();

    $this->actingAs($platformAdmin)
        ->getJson('/api/tenants')
        ->assertOk()
        ->assertJsonPath('data.0.id', $tenant->id)
        ->assertJsonPath('data.0.role', null);

    $this->actingAs($platformAdmin)
        ->getJson('/api/tenants/'.$tenant->id.'/projects')
        ->assertOk()
        ->assertJsonPath('data.0.id', $project->id);
});

it('creates and assigns a new account to a project atomically', function (): void {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);

    $response = $this->actingAs($owner)->postJson(
        '/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/user-accounts',
        [
            'name' => 'Project Engineer',
            'email' => 'engineer@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'engineer',
        ],
    );

    $response
        ->assertCreated()
        ->assertJsonPath('data.name', 'Project Engineer')
        ->assertJsonPath('data.role', 'engineer');

    $createdUser = User::query()->where('email', 'engineer@example.com')->firstOrFail();

    $this->assertDatabaseHas('tenant_user', [
        'tenant_id' => $tenant->id,
        'user_id' => $createdUser->id,
        'role' => 'member',
    ]);
    $this->assertDatabaseHas('project_user', [
        'project_id' => $project->id,
        'user_id' => $createdUser->id,
        'role' => 'engineer',
    ]);
});

it('assigns existing tenant users and removes only project membership', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);
    $tenant->users()->attach($member, ['role' => 'member']);

    $this->actingAs($owner)
        ->postJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/users', [
            'user_id' => $member->id,
            'role' => 'viewer',
        ])
        ->assertCreated()
        ->assertJsonPath('data.role', 'viewer');

    $this->actingAs($owner)
        ->patchJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/users/'.$member->id, [
            'role' => 'admin',
        ])
        ->assertOk()
        ->assertJsonPath('data.role', 'admin');

    $this->actingAs($owner)
        ->deleteJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/users/'.$member->id)
        ->assertNoContent();

    $this->assertDatabaseMissing('project_user', [
        'project_id' => $project->id,
        'user_id' => $member->id,
    ]);
    $this->assertDatabaseHas('users', ['id' => $member->id]);
    $this->assertDatabaseHas('tenant_user', [
        'tenant_id' => $tenant->id,
        'user_id' => $member->id,
    ]);
});

it('rejects hierarchy mutations for a viewer project member', function (): void {
    $viewer = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $tenant->users()->attach($viewer, ['role' => 'member']);
    $project->users()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($viewer)
        ->postJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/assets', [
            'name' => 'Viewer Asset',
            'code' => 'viewer-asset',
            'asset_type' => AssetType::Factory->value,
        ])
        ->assertForbidden();
});

it('allows an engineer project member to create hierarchy resources', function (): void {
    $engineer = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $tenant->users()->attach($engineer, ['role' => 'member']);
    $project->users()->attach($engineer, ['role' => 'engineer']);

    $this->actingAs($engineer)
        ->postJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/assets', [
            'name' => 'Engineer Asset',
            'code' => 'engineer-asset',
            'asset_type' => AssetType::Factory->value,
        ])
        ->assertCreated();
});

it('rejects cross-tenant and duplicate project assignments', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $otherTenant = Tenant::factory()->create();
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);
    $otherTenant->users()->attach($member, ['role' => 'member']);

    $this->actingAs($owner)
        ->postJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/users', [
            'user_id' => $member->id,
            'role' => 'viewer',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['user_id']);

    $tenant->users()->attach($member, ['role' => 'member']);

    $this->actingAs($owner)
        ->postJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/users', [
            'user_id' => $member->id,
            'role' => 'viewer',
        ])
        ->assertCreated();

    $this->actingAs($owner)
        ->postJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/users', [
            'user_id' => $member->id,
            'role' => 'viewer',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['user_id']);
});

it('rejects global account deletion for a tenant owner', function (): void {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);

    $this->actingAs($owner)
        ->deleteJson('/api/tenants/'.$tenant->id.'/users/'.$owner->id)
        ->assertForbidden();
});

it('allows a platform admin to delete a global account', function (): void {
    $platformAdmin = User::factory()->create(['system_role' => 'platform_admin']);
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);

    $this->actingAs($platformAdmin)
        ->deleteJson('/api/tenants/'.$tenant->id.'/users/'.$owner->id)
        ->assertNoContent();

    $this->assertDatabaseMissing('users', ['id' => $owner->id]);
});
