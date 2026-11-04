<?php

use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

/** @return array{tenant: Tenant, owner: User, admin: User, member: User} */
function roleFixture(): array
{
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $tenant->users()->attach($owner, ['role_id' => roleId($tenant, 'owner')]);
    $tenant->users()->attach($admin, ['role_id' => roleId($tenant, 'admin')]);
    $tenant->users()->attach($member, ['role_id' => roleId($tenant, 'member')]);

    return compact('tenant', 'owner', 'admin', 'member');
}

it('stops a tenant admin from changing the credentials or role of an owner', function (): void {
    ['tenant' => $tenant, 'owner' => $owner, 'admin' => $admin] = roleFixture();

    $this->actingAs($admin)
        ->patchJson("/api/tenants/$tenant->id/users/$owner->id", ['password' => 'hijacked1', 'password_confirmation' => 'hijacked1'])
        ->assertForbidden();
    $this->actingAs($admin)
        ->patchJson("/api/tenants/$tenant->id/users/$owner->id", ['role_id' => roleId($tenant, 'member')])
        ->assertForbidden();
    $this->actingAs($admin)
        ->deleteJson("/api/tenants/$tenant->id/users/$owner->id")
        ->assertForbidden();
});

it('stops a tenant admin from granting the owner role', function (): void {
    ['tenant' => $tenant, 'admin' => $admin, 'member' => $member] = roleFixture();

    $this->actingAs($admin)
        ->patchJson("/api/tenants/$tenant->id/users/$member->id", ['role_id' => roleId($tenant, 'owner')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role_id']);
});

it('stops anyone from editing or removing themselves through member management', function (): void {
    ['tenant' => $tenant, 'owner' => $owner] = roleFixture();

    $this->actingAs($owner)
        ->patchJson("/api/tenants/$tenant->id/users/$owner->id", ['role_id' => roleId($tenant, 'member')])
        ->assertForbidden();
    $this->actingAs($owner)
        ->deleteJson("/api/tenants/$tenant->id/users/$owner->id")
        ->assertForbidden();
});

it('lets a tenant admin promote a member and change their details', function (): void {
    ['tenant' => $tenant, 'admin' => $admin, 'member' => $member] = roleFixture();

    $this->actingAs($admin)
        ->patchJson("/api/tenants/$tenant->id/users/$member->id", ['name' => 'Promoted', 'role_id' => roleId($tenant, 'admin')])
        ->assertOk()
        ->assertJsonPath('data.name', 'Promoted')
        ->assertJsonPath('data.role_id', roleId($tenant, 'admin'));
});

it('does not let a tenant change the credentials of a person who also belongs to another tenant', function (): void {
    ['tenant' => $tenant, 'admin' => $admin, 'member' => $member] = roleFixture();
    $otherTenant = Tenant::factory()->create();
    $otherTenant->users()->attach($member, ['role_id' => roleId($otherTenant, 'owner')]);

    $this->actingAs($admin)
        ->patchJson("/api/tenants/$tenant->id/users/$member->id", ['email' => 'taken-over@example.com'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    $this->actingAs($admin)
        ->patchJson("/api/tenants/$tenant->id/users/$member->id", [
            'name' => $member->name,
            'email' => $member->email,
            'role_id' => roleId($tenant, 'admin'),
        ])
        ->assertOk();
});

it('removes a member from the tenant and its projects, deleting the account only when unused elsewhere', function (): void {
    ['tenant' => $tenant, 'admin' => $admin, 'member' => $member] = roleFixture();
    $project = Project::factory()->for($tenant)->create();
    $project->users()->attach($member, ['role_id' => roleId($project, 'viewer')]);
    $shared = User::factory()->create();
    $otherTenant = Tenant::factory()->create();
    $tenant->users()->attach($shared, ['role_id' => roleId($tenant, 'member')]);
    $otherTenant->users()->attach($shared, ['role_id' => roleId($otherTenant, 'member')]);

    $this->actingAs($admin)->deleteJson("/api/tenants/$tenant->id/users/$member->id")->assertNoContent();
    $this->actingAs($admin)->deleteJson("/api/tenants/$tenant->id/users/$shared->id")->assertNoContent();

    $this->assertDatabaseMissing('users', ['id' => $member->id]);
    $this->assertDatabaseMissing('project_user', ['user_id' => $member->id]);
    $this->assertDatabaseHas('users', ['id' => $shared->id]);
    $this->assertDatabaseMissing('tenant_user', ['tenant_id' => $tenant->id, 'user_id' => $shared->id]);
});

it('creates a custom project role whose permissions take effect immediately', function (): void {
    ['tenant' => $tenant, 'admin' => $admin] = roleFixture();
    $project = Project::factory()->for($tenant)->create();
    $technician = User::factory()->create();
    $tenant->users()->attach($technician, ['role_id' => roleId($tenant, 'member')]);

    $roleId = $this->actingAs($admin)
        ->postJson("/api/tenants/$tenant->id/roles", [
            'name' => 'Technician',
            'scope' => 'project',
            'permissions' => ['project.view', 'device.view', 'device.write'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.is_system', false)
        ->json('data.id');

    $project->users()->attach($technician, ['role_id' => $roleId]);

    expect($technician->can('create', [Device::class, $project]))->toBeTrue()
        ->and($technician->can('delete', $project))->toBeFalse();
});

it('records who created a custom role and when, leaving default roles without a creator', function (): void {
    ['tenant' => $tenant, 'admin' => $admin] = roleFixture();

    $this->actingAs($admin)
        ->postJson("/api/tenants/$tenant->id/roles", ['name' => 'Auditor', 'scope' => 'project', 'permissions' => ['project.view']])
        ->assertCreated()
        ->assertJsonPath('data.created_by.id', $admin->id)
        ->assertJsonPath('data.created_by.name', $admin->name)
        ->assertJsonStructure(['data' => ['created_at']]);

    $roles = $this->getJson("/api/tenants/$tenant->id/roles")->assertOk()->json('data');

    expect(collect($roles)->firstWhere('name', 'Auditor')['created_by']['id'])->toBe($admin->id)
        ->and(collect($roles)->firstWhere('key', 'owner')['created_by'])->toBeNull();
});

it('rejects tenant-only permissions on a project role', function (): void {
    ['tenant' => $tenant, 'admin' => $admin] = roleFixture();

    $this->actingAs($admin)
        ->postJson("/api/tenants/$tenant->id/roles", [
            'name' => 'Sneaky',
            'scope' => 'project',
            'permissions' => ['user.manage'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['permissions.0']);
});

it('protects system roles', function (): void {
    ['tenant' => $tenant, 'admin' => $admin] = roleFixture();
    $project = Project::factory()->for($tenant)->create();

    $this->actingAs($admin)
        ->patchJson("/api/tenants/$tenant->id/roles/".roleId($tenant, 'owner'), ['permissions' => []])
        ->assertForbidden();
    $this->actingAs($admin)
        ->deleteJson("/api/tenants/$tenant->id/roles/".roleId($project, 'admin'))
        ->assertForbidden();
    $this->actingAs($admin)
        ->patchJson("/api/tenants/$tenant->id/roles/".roleId($tenant, 'admin'), ['name' => 'Renamed'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
    $this->actingAs($admin)
        ->patchJson("/api/tenants/$tenant->id/roles/".roleId($project, 'admin'), ['permissions' => ['project.view']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['permissions']);
});

it('refuses to delete a role that is still assigned', function (): void {
    ['tenant' => $tenant, 'admin' => $admin] = roleFixture();

    $this->actingAs($admin)
        ->deleteJson("/api/tenants/$tenant->id/roles/".roleId($tenant, 'member'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role']);
});

it('keeps roles of another tenant out of reach', function (): void {
    ['tenant' => $tenant, 'admin' => $admin] = roleFixture();
    $otherTenant = Tenant::factory()->create();

    $this->actingAs($admin)
        ->patchJson("/api/tenants/$tenant->id/roles/".roleId($otherTenant, 'member'), ['name' => 'Hijacked'])
        ->assertNotFound();
});

it('lets members list roles but only role managers change them', function (): void {
    ['tenant' => $tenant, 'member' => $member] = roleFixture();

    $this->actingAs($member)
        ->getJson("/api/tenants/$tenant->id/roles?scope=project")
        ->assertOk()
        ->assertJsonCount(4, 'data');
    $this->actingAs($member)
        ->postJson("/api/tenants/$tenant->id/roles", ['name' => 'Mine', 'scope' => 'tenant', 'permissions' => ['user.manage']])
        ->assertForbidden();
});
