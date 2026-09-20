<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

it('rejects unauthenticated access to the current user endpoint', function (): void {
    $this->getJson('/api/me')
        ->assertUnauthorized();
});

it('authenticates a user with the stateful session flow', function (): void {
    $user = User::factory()->create([
        'email' => 'operator@example.com',
        'password' => 'correct-password',
    ]);

    $response = $this->withHeader('Origin', 'http://localhost:3000')
        ->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonMissingPath('data.password');

    $this->assertAuthenticatedAs($user);
});

it('does not authenticate invalid credentials or create a personal access token', function (): void {
    $user = User::factory()->create([
        'email' => 'operator@example.com',
        'password' => 'correct-password',
    ]);

    $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertUnauthorized();

    expect(PersonalAccessToken::query()->count())->toBe(0);
    $this->assertGuest();
});

it('returns authentication errors in English regardless of request language', function (): void {
    User::factory()->create([
        'email' => 'localized@example.com',
        'password' => 'correct-password',
    ]);

    $this->withHeader('Accept-Language', 'vi')
        ->postJson('/api/login', [
            'email' => 'localized@example.com',
            'password' => 'wrong-password',
        ])
        ->assertUnauthorized()
        ->assertJsonPath('message', 'The provided credentials are invalid.');

    $this->withHeader('Accept-Language', 'en')
        ->postJson('/api/login', [
            'email' => 'localized@example.com',
            'password' => 'wrong-password',
        ])
        ->assertUnauthorized()
        ->assertJsonPath('message', 'The provided credentials are invalid.');
});

it('returns English validation messages regardless of request language', function (): void {
    $this->withHeader('Accept-Language', 'vi')
        ->postJson('/api/login', [
            'email' => 'not-an-email',
            'password' => 'wrong-password',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'The email must be a valid email address.');
});

it('returns the authenticated user and tenant memberships', function (): void {
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $user->tenants()->attach($tenant, ['role' => 'admin']);

    $response = $this->actingAs($user)->getJson('/api/me');

    $response
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.tenants.0.id', $tenant->id)
        ->assertJsonPath('data.tenants.0.role', 'admin');
});

it('creates a personal access token and never returns it from the list endpoint', function (): void {
    $user = User::factory()->create();

    $createResponse = $this->actingAs($user)->postJson('/api/tokens', [
        'name' => 'CI integration',
        'abilities' => ['control-plane:read'],
        'expires_in_days' => 30,
    ]);

    $createResponse
        ->assertCreated()
        ->assertJsonPath('data.name', 'CI integration')
        ->assertJsonPath('data.abilities.0', 'control-plane:read');

    $plainTextToken = $createResponse->json('data.token');

    expect($plainTextToken)->toBeString()->not->toBeEmpty();

    $this->actingAs($user)->getJson('/api/tokens')
        ->assertOk()
        ->assertJsonMissing(['token' => $plainTextToken])
        ->assertJsonPath('data.0.name', 'CI integration');
});

it('returns an English validation message for an excessive token expiration', function (): void {
    $user = User::factory()->create();

    $this->withHeader('Accept-Language', 'vi')
        ->actingAs($user)
        ->postJson('/api/tokens', [
            'name' => 'CI integration',
            'abilities' => ['control-plane:read'],
            'expires_in_days' => 91,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.expires_in_days.0', 'The expiration days may not be greater than 90.');
});

it('revokes a personal access token', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('integration', ['control-plane:read']);

    $this->actingAs($user)->deleteJson('/api/tokens/'.$token->accessToken->id)
        ->assertNoContent();

    expect(PersonalAccessToken::query()->find($token->accessToken->id))->toBeNull();
});

it('rejects a revoked personal access token', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('integration', ['control-plane:read']);
    $token->accessToken->delete();

    $this->withToken($token->plainTextToken)
        ->getJson('/api/me')
        ->assertUnauthorized();
});

it('rejects an expired personal access token', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken(
        'expired',
        ['control-plane:read'],
        CarbonImmutable::now()->subDay(),
    );

    $this->withToken($token->plainTextToken)
        ->getJson('/api/me')
        ->assertUnauthorized();
});

it('does not let token abilities bypass endpoint policy', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('read-only', ['control-plane:read']);

    $this->withToken($token->plainTextToken)
        ->postJson('/api/tokens', [
            'name' => 'nested',
            'abilities' => ['control-plane:read'],
        ])
        ->assertForbidden();
});

it('rotates a personal access token and invalidates the old secret', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('integration', ['control-plane:read', 'control-plane:write']);

    $response = $this->actingAs($user)
        ->postJson('/api/tokens/'.$token->accessToken->id.'/rotate')
        ->assertOk()
        ->assertJsonPath('data.name', 'integration');

    $newPlainTextToken = $response->json('data.token');

    expect($newPlainTextToken)->toBeString()->not->toBeEmpty()
        ->and(PersonalAccessToken::query()->find($token->accessToken->id))->toBeNull();
});

it('revokes the current personal access token on logout', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('integration', ['control-plane:read']);

    $this->withToken($token->plainTextToken)
        ->postJson('/api/logout')
        ->assertNoContent();

    expect(PersonalAccessToken::query()->find($token->accessToken->id))->toBeNull();
});

it('revokes all personal access tokens on logout all', function (): void {
    $user = User::factory()->create();
    $user->createToken('first', ['control-plane:read']);
    $user->createToken('second', ['control-plane:write']);

    $this->actingAs($user)->postJson('/api/logout-all')
        ->assertNoContent();

    expect(PersonalAccessToken::query()->where('tokenable_id', $user->id)->count())->toBe(0);
});

it('rate limits repeated login attempts', function (): void {
    RateLimiter::clear('operator@example.com|127.0.0.1');

    User::factory()->create([
        'email' => 'operator@example.com',
        'password' => 'correct-password',
    ]);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/login', [
            'email' => 'operator@example.com',
            'password' => 'wrong-password',
        ])->assertUnauthorized();
    }

    $this->postJson('/api/login', [
        'email' => 'operator@example.com',
        'password' => 'wrong-password',
    ])->assertTooManyRequests();
});
