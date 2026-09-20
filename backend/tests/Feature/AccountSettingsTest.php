<?php

use Illuminate\Support\Facades\Hash;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

it('rejects unauthenticated profile and password updates', function (): void {
    $this->patchJson('/api/me', [
        'name' => 'Updated User',
        'email' => 'updated@example.com',
    ])->assertUnauthorized();

    $this->patchJson('/api/me/password', [
        'current_password' => 'current-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertUnauthorized();
});

it('updates only the authenticated user profile and returns tenant roles', function (): void {
    $user = User::factory()->create([
        'email' => 'operator@example.com',
    ]);
    $tenant = Tenant::factory()->create([
        'name' => 'Operations',
    ]);
    $user->tenants()->attach($tenant, ['role' => 'admin']);

    $this->actingAs($user)
        ->patchJson('/api/me', [
            'name' => 'Updated Operator',
            'email' => 'updated@example.com',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated Operator')
        ->assertJsonPath('data.email', 'updated@example.com')
        ->assertJsonPath('data.email_verified_at', null)
        ->assertJsonPath('data.tenants.0.name', 'Operations')
        ->assertJsonPath('data.tenants.0.role', 'admin')
        ->assertJsonMissingPath('data.password');

    expect($user->refresh()->name)->toBe('Updated Operator')
        ->and($user->email)->toBe('updated@example.com')
        ->and($user->email_verified_at)->toBeNull();
});

it('rejects profile updates that try to change role or another user', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create([
        'name' => 'Other User',
    ]);
    $tenant = Tenant::factory()->create();
    $user->tenants()->attach($tenant, ['role' => 'admin']);

    $this->actingAs($user)
        ->patchJson('/api/me', [
            'name' => 'Updated User',
            'email' => $user->email,
            'role' => 'owner',
            'user_id' => $otherUser->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['role', 'user_id']);

    expect($user->refresh()->name)->not->toBe('Updated User')
        ->and($user->tenants()->whereKey($tenant->id)->firstOrFail()->pivot->role)->toBe('admin')
        ->and($otherUser->refresh()->name)->toBe('Other User');
});

it('rejects an email already used by another user', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create([
        'email' => 'existing@example.com',
    ]);

    $this->actingAs($user)
        ->patchJson('/api/me', [
            'name' => $user->name,
            'email' => $otherUser->email,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('changes the authenticated user password after validating the current password', function (): void {
    $user = User::factory()->create([
        'password' => 'current-password',
    ]);

    $this->actingAs($user)
        ->patchJson('/api/me/password', [
            'current_password' => 'current-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertNoContent();

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

it('rejects an incorrect current password and mismatched confirmation', function (): void {
    $user = User::factory()->create([
        'password' => 'current-password',
    ]);

    $this->actingAs($user)
        ->patchJson('/api/me/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);

    $this->actingAs($user)
        ->patchJson('/api/me/password', [
            'current_password' => 'current-password',
            'password' => 'new-password',
            'password_confirmation' => 'different-password',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);

    expect(Hash::check('current-password', $user->refresh()->password))->toBeTrue();
});
