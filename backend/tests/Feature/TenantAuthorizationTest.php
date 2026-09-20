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
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);
    $tenant->users()->attach($admin, ['role' => 'admin']);
    $tenant->users()->attach($member, ['role' => 'member']);

    expect($owner->can('delete', $tenant))->toBeTrue()
        ->and($admin->can('create', Tenant::class))->toBeTrue()
        ->and($admin->can('update', $tenant))->toBeTrue()
        ->and($member->can('create', Tenant::class))->toBeFalse()
        ->and($member->can('update', $tenant))->toBeFalse();
});

it('allows an owner to create a tenant and becomes its owner', function (): void {
    $owner = User::factory()->create();
    $existingTenant = Tenant::factory()->create();
    $existingTenant->users()->attach($owner, ['role' => 'owner']);

    $response = $this->actingAs($owner)->postJson('/api/tenants', [
        'name' => 'New Tenant',
        'code' => 'new-tenant',
        'status' => 'active',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.name', 'New Tenant')
        ->assertJsonPath('data.role', 'owner');

    expect($owner->tenants()->where('code', 'new-tenant')->wherePivot('role', 'owner')->exists())->toBeTrue();
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
    $admin = User::factory()->create();
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
