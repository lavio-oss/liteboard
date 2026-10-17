<?php

use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

/** @return array<string, mixed> */
function decodePresenceTicketClaims(string $ticket): array
{
    [$payload] = explode('.', $ticket, 2);
    $decoded = base64_decode(strtr($payload, '-_', '+/').str_repeat('=', (4 - strlen($payload) % 4) % 4), true);

    if (! is_string($decoded)) {
        throw new RuntimeException('The presence ticket payload is not valid base64.');
    }

    $claims = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($claims)) {
        throw new RuntimeException('The presence ticket claims are not an object.');
    }

    return $claims;
}

it('issues a tenant scoped presence ticket to a user who can view tenant devices', function (): void {
    config(['services.telemetry.ws_secret' => str_repeat('s', 32)]);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($user, ['role' => 'owner']);

    $response = $this->actingAs($user)
        ->postJson("/api/tenants/$tenant->id/devices/presence-stream-ticket")
        ->assertCreated();

    $claims = decodePresenceTicketClaims($response->json('data.ticket'));

    expect($claims['sub'])->toBe((string) $user->getKey())
        ->and($claims['tenant_id'])->toBe((string) $tenant->getKey())
        ->and($claims['scope'])->toBe('device-presence')
        ->and($claims['exp'])->toBeInt();
});

it('denies presence tickets for tenants where the user cannot view devices', function (): void {
    config(['services.telemetry.ws_secret' => str_repeat('s', 32)]);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($user, ['role' => 'viewer']);

    $this->actingAs($user)
        ->postJson("/api/tenants/$tenant->id/devices/presence-stream-ticket")
        ->assertForbidden();
});

it('returns not found when a user tries to issue a presence ticket for another tenant', function (): void {
    config(['services.telemetry.ws_secret' => str_repeat('s', 32)]);
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();

    $this->actingAs($user)
        ->postJson("/api/tenants/$tenant->id/devices/presence-stream-ticket")
        ->assertNotFound();
});
