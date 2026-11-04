<?php

use Modules\Admin\Enums\Asset\AssetType;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Enums\Role\Permission;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

it('lets a tenant owner see every project without a project membership', function (): void {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $tenant->users()->attach($owner, ['role_id' => roleId($tenant, 'owner')]);

    $this->actingAs($owner)
        ->getJson('/api/tenants/'.$tenant->id.'/projects')
        ->assertOk()
        ->assertJsonPath('data.0.id', $project->id)
        ->assertJsonFragment(['permissions' => array_map(
            static fn ($permission) => $permission->value,
            Permission::cases(),
        )]);
});

it('creates and assigns a new account to a project atomically', function (): void {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $tenant->users()->attach($owner, ['role_id' => roleId($tenant, 'owner')]);

    $response = $this->actingAs($owner)->postJson(
        '/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/user-accounts',
        [
            'name' => 'Project Engineer',
            'email' => 'engineer@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role_id' => roleId($project, 'engineer'),
        ],
    );

    $response
        ->assertCreated()
        ->assertJsonPath('data.name', 'Project Engineer')
        ->assertJsonPath('data.role_id', roleId($project, 'engineer'));

    $createdUser = User::query()->where('email', 'engineer@example.com')->firstOrFail();

    $this->assertDatabaseHas('tenant_user', [
        'tenant_id' => $tenant->id,
        'user_id' => $createdUser->id,
        'role_id' => roleId($tenant, 'member'),
    ]);
    $this->assertDatabaseHas('project_user', [
        'project_id' => $project->id,
        'user_id' => $createdUser->id,
        'role_id' => roleId($project, 'engineer'),
    ]);
});

it('assigns existing tenant users and removes only project membership', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $tenant->users()->attach($owner, ['role_id' => roleId($tenant, 'owner')]);
    $tenant->users()->attach($member, ['role_id' => roleId($tenant, 'member')]);

    $this->actingAs($owner)
        ->postJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/users', [
            'user_id' => 'not-a-uuid',
            'role_id' => roleId($project, 'viewer'),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['user_id']);

    $this->actingAs($owner)
        ->postJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/users', [
            'user_id' => $member->id,
            'role_id' => roleId($tenant, 'admin'),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role_id']);

    $this->actingAs($owner)
        ->postJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/users', [
            'user_id' => $member->id,
            'role_id' => roleId($project, 'viewer'),
        ])
        ->assertCreated()
        ->assertJsonPath('data.role_id', roleId($project, 'viewer'));

    $this->actingAs($owner)
        ->patchJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/users/'.$member->id, [
            'role_id' => roleId($project, 'admin'),
        ])
        ->assertOk()
        ->assertJsonPath('data.role_id', roleId($project, 'admin'));

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

it('lets a project admin manage project members but not create tenant accounts', function (): void {
    $projectAdmin = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $tenant->users()->attach($projectAdmin, ['role_id' => roleId($tenant, 'member')]);
    $tenant->users()->attach($member, ['role_id' => roleId($tenant, 'member')]);
    $project->users()->attach($projectAdmin, ['role_id' => roleId($project, 'admin')]);

    $this->actingAs($projectAdmin)
        ->postJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/users', [
            'user_id' => $member->id,
            'role_id' => roleId($project, 'operator'),
        ])
        ->assertCreated();

    $this->actingAs($projectAdmin)
        ->postJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/user-accounts', [
            'name' => 'New Person',
            'email' => 'new-person@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role_id' => roleId($project, 'viewer'),
        ])
        ->assertForbidden();
});

it('lists tenant users not yet on the project with their tenant role', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $tenant->users()->attach($owner, ['role_id' => roleId($tenant, 'owner')]);
    $tenant->users()->attach($member, ['role_id' => roleId($tenant, 'member')]);

    $this->actingAs($owner)
        ->getJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/available-users?search='.$member->email)
        ->assertOk()
        ->assertJsonPath('data.0.id', $member->id)
        ->assertJsonPath('data.0.tenant_role_id', roleId($tenant, 'member'));
});

it('rejects hierarchy mutations for a viewer project member', function (): void {
    $viewer = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $tenant->users()->attach($viewer, ['role_id' => roleId($tenant, 'member')]);
    $project->users()->attach($viewer, ['role_id' => roleId($project, 'viewer')]);

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
    $tenant->users()->attach($engineer, ['role_id' => roleId($tenant, 'member')]);
    $project->users()->attach($engineer, ['role_id' => roleId($project, 'engineer')]);

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
    $tenant->users()->attach($owner, ['role_id' => roleId($tenant, 'owner')]);
    $otherTenant->users()->attach($member, ['role_id' => roleId($otherTenant, 'member')]);
    $payload = ['user_id' => $member->id, 'role_id' => roleId($project, 'viewer')];

    $this->actingAs($owner)
        ->postJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/users', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['user_id']);

    $tenant->users()->attach($member, ['role_id' => roleId($tenant, 'member')]);

    $this->actingAs($owner)
        ->postJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/users', $payload)
        ->assertCreated();

    $this->actingAs($owner)
        ->postJson('/api/tenants/'.$tenant->id.'/projects/'.$project->id.'/users', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['user_id']);
});

it('makes the project creator its admin', function (): void {
    $admin = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($admin, ['role_id' => roleId($tenant, 'admin')]);

    $projectId = $this->actingAs($admin)
        ->postJson('/api/tenants/'.$tenant->id.'/projects', ['name' => 'Fresh Project'])
        ->assertCreated()
        ->json('data.id');

    $this->assertDatabaseHas('project_user', [
        'project_id' => $projectId,
        'user_id' => $admin->id,
        'role_id' => roleId(Project::query()->findOrFail($projectId), 'admin'),
    ]);
});

it('filters participating projects for a tenant-wide viewer', function (): void {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $assignedProject = Project::factory()->for($tenant)->create(['name' => 'Assigned Project']);
    Project::factory()->for($tenant)->create(['name' => 'Unassigned Project']);
    $tenant->users()->attach($owner, ['role_id' => roleId($tenant, 'owner')]);
    $assignedProject->users()->attach($owner, ['role_id' => roleId($assignedProject, 'admin')]);

    $this->actingAs($owner)
        ->getJson('/api/tenants/'.$tenant->id.'/projects')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAs($owner)
        ->getJson('/api/tenants/'.$tenant->id.'/projects?participating=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $assignedProject->id);
});

it('lists only assigned projects with the member permissions on each', function (): void {
    $viewer = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $assignedProject = Project::factory()->for($tenant)->create(['name' => 'Assigned Project']);
    Project::factory()->for($tenant)->create(['name' => 'Unassigned Project']);
    $tenant->users()->attach($viewer, ['role_id' => roleId($tenant, 'member')]);
    $assignedProject->users()->attach($viewer, ['role_id' => roleId($assignedProject, 'viewer')]);

    $this->actingAs($viewer)
        ->getJson('/api/tenants/'.$tenant->id.'/projects')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.permissions', [
            'project.view',
            'device.view',
            'asset.view',
            'device_profile.view',
            'alert_rule.view',
            'alert.view',
        ]);
});
