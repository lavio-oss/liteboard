<?php

use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

it('lists only tenants that belong to the authenticated user', function (): void {
    $user = User::factory()->create();
    $ownedTenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();
    $user->tenants()->attach($ownedTenant, ['role' => 'member']);

    $response = $this->actingAs($user)->getJson('/api/tenants');

    $response
        ->assertOk()
        ->assertJsonPath('data.0.id', $ownedTenant->id)
        ->assertJsonMissing(['id' => $otherTenant->id]);
});

it('returns a tenant resource to a member of that tenant', function (): void {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $user->tenants()->attach($tenant, ['role' => 'member']);

    $this->actingAs($user)
        ->getJson('/api/tenants/'.$tenant->id)
        ->assertOk()
        ->assertJsonPath('data.id', $tenant->id)
        ->assertJsonPath('data.role', 'member');
});

it('lists users only for a tenant manager', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);
    $tenant->users()->attach($member, ['role' => 'member']);

    $this->actingAs($owner)
        ->getJson('/api/tenants/'.$tenant->id.'/users')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonFragment(['id' => $owner->id])
        ->assertJsonFragment(['id' => $member->id]);

    expect($member->can('manageUsers', $tenant))->toBeFalse();

});

it('lets platform admins list users in any tenant', function (): void {
    $platformAdmin = User::factory()->create(['system_role' => 'platform_admin']);
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);

    $this->actingAs($platformAdmin)
        ->getJson('/api/tenants/'.$tenant->id.'/users')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('allows a tenant manager to create a member account', function (): void {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);

    $response = $this->actingAs($owner)->postJson('/api/tenants/'.$tenant->id.'/users', [
        'name' => 'New Tenant User',
        'email' => 'new-user@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.name', 'New Tenant User')
        ->assertJsonPath('data.email', 'new-user@example.com')
        ->assertJsonPath('data.role', 'member');

    expect($tenant->users()->where('email', 'new-user@example.com')->wherePivot('role', 'member')->exists())->toBeTrue();
});

it('rejects tenant user creation for a regular member', function (): void {
    $member = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($member, ['role' => 'member']);

    $this->actingAs($member)
        ->postJson('/api/tenants/'.$tenant->id.'/users', [
            'name' => 'Blocked User',
            'email' => 'blocked-user@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
        ->assertForbidden();
});

it('rejects duplicate tenant user emails', function (): void {
    $owner = User::factory()->create();
    $existingUser = User::factory()->create(['email' => 'existing@example.com']);
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);

    $this->actingAs($owner)
        ->postJson('/api/tenants/'.$tenant->id.'/users', [
            'name' => 'Duplicate User',
            'email' => $existingUser->email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
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
    $user->tenants()->attach($ownedTenant, ['role' => 'member']);

    $this->actingAs($user)
        ->withHeader('X-Tenant-ID', (string) $otherTenant->id)
        ->getJson('/api/tenants/'.$ownedTenant->id)
        ->assertOk()
        ->assertJsonPath('data.id', $ownedTenant->id);
});

it('enforces the tenant policy role matrix', function (): void {
    $platformAdmin = User::factory()->create(['system_role' => 'platform_admin']);
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);
    $tenant->users()->attach($admin, ['role' => 'admin']);
    $tenant->users()->attach($member, ['role' => 'member']);

    expect($platformAdmin->can('create', Tenant::class))->toBeTrue()
        ->and($owner->can('create', Tenant::class))->toBeTrue()
        ->and($admin->can('create', Tenant::class))->toBeTrue()
        ->and($owner->can('delete', $tenant))->toBeTrue()
        ->and($admin->can('update', $tenant))->toBeTrue()
        ->and($member->can('create', Tenant::class))->toBeFalse()
        ->and($member->can('update', $tenant))->toBeFalse();
});

it('allows a platform admin to create a tenant without membership', function (): void {
    $platformAdmin = User::factory()->create(['system_role' => 'platform_admin']);

    $response = $this->actingAs($platformAdmin)->postJson('/api/tenants', [
        'name' => 'New Tenant',
        'code' => 'new-tenant',
        'status' => 'active',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.name', 'New Tenant')
        ->assertJsonPath('data.role', null);

    expect($platformAdmin->tenants()->where('code', 'new-tenant')->exists())->toBeFalse();
});

it('allows an owner to create a tenant and become its owner', function (): void {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);

    $this->actingAs($owner)
        ->postJson('/api/tenants', [
            'name' => 'Owner Tenant',
            'code' => 'owner-tenant',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Owner Tenant')
        ->assertJsonPath('data.role', 'owner');
});

it('allows an admin to create a tenant and become its owner', function (): void {
    $admin = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($admin, ['role' => 'admin']);

    $this->actingAs($admin)
        ->postJson('/api/tenants', [
            'name' => 'Admin Tenant',
            'code' => 'admin-tenant',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Admin Tenant')
        ->assertJsonPath('data.role', 'owner');
});

it('allows an admin to update an accessible tenant', function (): void {
    $admin = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($admin, ['role' => 'admin']);

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

it('allows only an owner to delete a tenant', function (): void {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);

    $this->actingAs($owner)
        ->deleteJson('/api/tenants/'.$tenant->id)
        ->assertNoContent();

    expect(Tenant::query()->whereKey($tenant->id)->exists())->toBeFalse();
});

it('rejects duplicate tenant codes', function (): void {
    $admin = User::factory()->create(['system_role' => 'platform_admin']);
    $tenant = Tenant::factory()->create(['code' => 'existing-tenant']);
    $admin->tenants()->attach($tenant, ['role' => 'admin']);

    $this->actingAs($admin)
        ->postJson('/api/tenants', [
            'name' => 'Duplicate Tenant',
            'code' => 'existing-tenant',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});
