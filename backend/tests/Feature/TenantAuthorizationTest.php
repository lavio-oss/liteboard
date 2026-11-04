<?php

use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

it('lists only tenants that belong to the authenticated user', function (): void {
    $user = User::factory()->create();
    $ownedTenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();
    $user->tenants()->attach($ownedTenant, ['role_id' => roleId($ownedTenant, 'member')]);

    $response = $this->actingAs($user)->getJson('/api/tenants');

    $response
        ->assertOk()
        ->assertJsonPath('data.0.id', $ownedTenant->id)
        ->assertJsonMissing(['id' => $otherTenant->id]);
});

it('returns a tenant resource with the member role and permissions', function (): void {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $user->tenants()->attach($tenant, ['role_id' => roleId($tenant, 'member')]);

    $this->actingAs($user)
        ->getJson('/api/tenants/'.$tenant->id)
        ->assertOk()
        ->assertJsonPath('data.id', $tenant->id)
        ->assertJsonPath('data.role.key', 'member')
        ->assertJsonPath('data.permissions', []);
});

it('lists users only for a tenant manager', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role_id' => roleId($tenant, 'owner')]);
    $tenant->users()->attach($member, ['role_id' => roleId($tenant, 'member')]);

    $this->actingAs($owner)
        ->getJson('/api/tenants/'.$tenant->id.'/users')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonFragment(['id' => $owner->id])
        ->assertJsonFragment(['id' => $member->id]);

    expect($member->can('manageUsers', $tenant))->toBeFalse();
});

it('lists each user with creation date and the tenant projects they belong to', function (): void {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $otherProject = Project::factory()->for(Tenant::factory()->create())->create();
    $tenant->users()->attach($owner, ['role_id' => roleId($tenant, 'owner')]);
    $owner->projects()->attach($project, ['role_id' => roleId($project, 'viewer')]);
    $owner->projects()->attach($otherProject, ['role_id' => roleId($otherProject, 'viewer')]);

    $this->actingAs($owner)
        ->getJson('/api/tenants/'.$tenant->id.'/users')
        ->assertOk()
        ->assertJsonPath('data.0.created_at', $owner->created_at?->toISOString())
        ->assertJsonCount(1, 'data.0.projects')
        ->assertJsonPath('data.0.projects.0.id', $project->id);
});

it('allows a tenant manager to create an account with a tenant role', function (): void {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role_id' => roleId($tenant, 'owner')]);

    $response = $this->actingAs($owner)->postJson('/api/tenants/'.$tenant->id.'/users', [
        'name' => 'New Tenant User',
        'email' => 'new-user@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role_id' => roleId($tenant, 'admin'),
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.name', 'New Tenant User')
        ->assertJsonPath('data.email', 'new-user@example.com')
        ->assertJsonPath('data.role_id', roleId($tenant, 'admin'));
});

it('rejects a project role or another tenant role for a tenant member', function (): void {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role_id' => roleId($tenant, 'owner')]);

    foreach ([roleId($tenant->projects()->create(['name' => 'P']), 'viewer'), roleId($otherTenant, 'admin')] as $roleId) {
        $this->actingAs($owner)
            ->postJson('/api/tenants/'.$tenant->id.'/users', [
                'name' => 'New Tenant User',
                'email' => 'new-user@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'role_id' => $roleId,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role_id']);
    }
});

it('rejects tenant user creation for a regular member', function (): void {
    $member = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($member, ['role_id' => roleId($tenant, 'member')]);

    $this->actingAs($member)
        ->postJson('/api/tenants/'.$tenant->id.'/users', [
            'name' => 'Blocked User',
            'email' => 'blocked-user@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role_id' => roleId($tenant, 'member'),
        ])
        ->assertForbidden();
});

it('rejects duplicate tenant user emails', function (): void {
    $owner = User::factory()->create();
    $existingUser = User::factory()->create(['email' => 'existing@example.com']);
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role_id' => roleId($tenant, 'owner')]);

    $this->actingAs($owner)
        ->postJson('/api/tenants/'.$tenant->id.'/users', [
            'name' => 'Duplicate User',
            'email' => $existingUser->email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role_id' => roleId($tenant, 'member'),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('hides a tenant from a user in another tenant', function (): void {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/tenants/'.$tenant->id)
        ->assertNotFound();
});

it('does not allow a fake tenant header to change the tenant context', function (): void {
    $user = User::factory()->create();
    $ownedTenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();
    $user->tenants()->attach($ownedTenant, ['role_id' => roleId($ownedTenant, 'member')]);

    $this->actingAs($user)
        ->withHeader('X-Tenant-ID', (string) $otherTenant->id)
        ->getJson('/api/tenants/'.$ownedTenant->id)
        ->assertOk()
        ->assertJsonPath('data.id', $ownedTenant->id);
});

it('enforces the tenant policy role matrix', function (): void {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role_id' => roleId($tenant, 'owner')]);
    $tenant->users()->attach($admin, ['role_id' => roleId($tenant, 'admin')]);
    $tenant->users()->attach($member, ['role_id' => roleId($tenant, 'member')]);

    expect($owner->can('update', $tenant))->toBeTrue()
        ->and($admin->can('update', $tenant))->toBeTrue()
        ->and($admin->can('manageRoles', $tenant))->toBeTrue()
        ->and($member->can('update', $tenant))->toBeFalse()
        ->and($member->can('manageUsers', $tenant))->toBeFalse();
});

it('no longer exposes tenant creation or deletion', function (): void {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role_id' => roleId($tenant, 'owner')]);

    $this->actingAs($owner)
        ->postJson('/api/tenants', ['name' => 'Second Tenant', 'code' => 'second-tenant'])
        ->assertMethodNotAllowed();

    $this->actingAs($owner)
        ->deleteJson('/api/tenants/'.$tenant->id)
        ->assertMethodNotAllowed();

    expect(Tenant::query()->count())->toBe(1);
});

it('allows an admin to update an accessible tenant', function (): void {
    $admin = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($admin, ['role_id' => roleId($tenant, 'admin')]);

    $this->actingAs($admin)
        ->patchJson('/api/tenants/'.$tenant->id, [
            'name' => 'Updated Tenant',
            'code' => 'updated-tenant',
            'status' => 'inactive',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated Tenant')
        ->assertJsonPath('data.status', 'inactive');
});

it('rejects duplicate tenant codes', function (): void {
    $admin = User::factory()->create();
    Tenant::factory()->create(['code' => 'existing-tenant']);
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($admin, ['role_id' => roleId($tenant, 'admin')]);

    $this->actingAs($admin)
        ->patchJson('/api/tenants/'.$tenant->id, ['code' => 'existing-tenant'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});
