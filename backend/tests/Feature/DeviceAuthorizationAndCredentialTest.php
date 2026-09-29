<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Admin\Enums\Asset\AssetType;
use Modules\Admin\Enums\Device\DeviceConnectionState;
use Modules\Admin\Enums\Device\DeviceCredentialStatus;
use Modules\Admin\Enums\Device\DeviceCredentialType;
use Modules\Admin\Enums\Device\DeviceStatus;
use Modules\Admin\Models\Asset\Asset;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Device\DeviceCredential;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

it('rejects unauthenticated device and credential access', function (): void {
    $tenantId = '019c0000-0000-7000-8000-000000000001';
    $projectId = '019c0000-0000-7000-8000-000000000002';
    $deviceId = '019c0000-0000-7000-8000-000000000003';

    $this->getJson("/api/tenants/$tenantId/devices")
        ->assertUnauthorized();

    $this->getJson("/api/tenants/$tenantId/projects/$projectId/devices")
        ->assertUnauthorized();

    $this->getJson("/api/tenants/$tenantId/projects/$projectId/devices/$deviceId/credentials")
        ->assertUnauthorized();
});

it('creates a device with an auto-generated access token and populates mqtt views', function (): void {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);
    $project = Project::factory()->for($tenant)->create();
    $equipment = Asset::factory()->for($project)->ofType(AssetType::Equipment)->create();

    $response = $this->actingAs($owner)->postJson(
        "/api/tenants/$tenant->id/projects/$project->id/devices",
        [
            'name' => 'Kiln Sensor Node 01',
            'code' => 'kiln-sensor-01',
            'description' => 'ESP32 temperature and vibration node',
            'equipment_asset_id' => $equipment->getKey(),
        ],
    );

    $response
        ->assertCreated()
        ->assertJsonPath('data.project_id', $project->getKey())
        ->assertJsonPath('data.equipment_asset_id', $equipment->getKey())
        ->assertJsonPath('data.name', 'Kiln Sensor Node 01')
        ->assertJsonPath('data.code', 'kiln-sensor-01')
        ->assertJsonPath('data.status', DeviceStatus::Active->value)
        ->assertJsonPath('data.connection_state', DeviceConnectionState::Offline->value)
        ->assertJsonPath('data.credential_type', DeviceCredentialType::AccessToken->value)
        ->assertJsonPath('data.credential_status', DeviceCredentialStatus::Active->value)
        ->assertJsonMissingPath('data.access_token');

    $device = Device::query()->where('code', 'kiln-sensor-01')->firstOrFail();
    $this->assertTrue(Str::isUuid($device->id, 7));

    $response->assertJsonPath(
        'data.mqtt_topics.telemetry',
        "liteboard/$project->id/devices/$device->id/telemetry",
    );

    $credentialResponse = $this->actingAs($owner)->getJson(
        "/api/tenants/$tenant->id/projects/$project->id/devices/$device->id/credentials",
    );

    $credentialResponse
        ->assertOk()
        ->assertJsonPath('data.device_id', $device->getKey())
        ->assertJsonPath('data.project_id', $project->getKey())
        ->assertJsonPath('data.credential_type', DeviceCredentialType::AccessToken->value)
        ->assertJsonPath('data.status', DeviceCredentialStatus::Active->value);

    $plainToken = (string) $credentialResponse->json('data.access_token');
    expect($plainToken)->toMatch('/^[0-9a-z]{20}$/');

    $credential = DeviceCredential::query()->where('device_id', $device->getKey())->firstOrFail();
    $expectedHash = hash('sha256', $plainToken);

    expect($credential->token_hash)->toBe($expectedHash);
    expect($credential->mqtt_password_hash)->toStartWith('$2a$');
    expect(Hash::check($plainToken, str_replace('$2a$', '$2y$', $credential->mqtt_password_hash)))->toBeTrue();

    $rawEncrypted = (string) DB::table('device_credentials')
        ->where('device_id', $device->getKey())
        ->value('encrypted_token');
    expect($rawEncrypted)->not->toBe($plainToken);

    $mqttUser = DB::table('v_mqtt_users')->where('token_hash', $expectedHash)->first();
    expect($mqttUser)->not->toBeNull();
    expect($mqttUser?->device_id)->toBe($device->getKey());
    expect($mqttUser?->project_id)->toBe($project->getKey());

    $acls = DB::table('v_mqtt_acls')
        ->where('token_hash', $expectedHash)
        ->orderBy('rw')
        ->orderBy('topic')
        ->get();

    expect($acls)->toHaveCount(5);
    expect($acls->where('rw', 2)->pluck('topic')->all())->toEqualCanonicalizing([
        "liteboard/$project->id/devices/$device->id/telemetry",
        "liteboard/$project->id/devices/$device->id/heartbeat",
        "liteboard/$project->id/devices/$device->id/commands/ack",
    ]);
    expect($acls->whereIn('rw', [1, 4])->pluck('topic')->unique()->values()->all())->toBe([
        "liteboard/$project->id/devices/$device->id/commands",
    ]);
});

it('rejects custom access tokens when creating devices or updating credentials', function (): void {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);
    $project = Project::factory()->for($tenant)->create();

    $this->actingAs($owner)->postJson(
        "/api/tenants/$tenant->id/projects/$project->id/devices",
        [
            'name' => 'Custom Token Device',
            'code' => 'custom-token-01',
            'access_token' => 'user_selected_token_123',
        ],
    )->assertUnprocessable()->assertJsonValidationErrors(['access_token']);

    $this->actingAs($owner)
        ->postJson("/api/tenants/$tenant->id/projects/$project->id/devices", [
            'name' => 'Null Token Device',
            'code' => 'null-token-01',
            'access_token' => null,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['access_token']);

    $this->actingAs($owner)
        ->postJson("/api/tenants/$tenant->id/projects/$project->id/devices", [
            'name' => 'Generated Token Device',
            'code' => 'generated-token-01',
        ])
        ->assertCreated()
        ->assertJsonMissingPath('data.access_token');

    $device = Device::query()->where('code', 'generated-token-01')->firstOrFail();
    $credential = $device->credential()->firstOrFail();
    $originalTokenHash = $credential->token_hash;

    $this->actingAs($owner)
        ->putJson("/api/tenants/$tenant->id/projects/$project->id/devices/$device->id/credentials", [
            'access_token' => 'custom_token_20_chars',
            'status' => DeviceCredentialStatus::Revoked->value,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['access_token']);

    $credential->refresh();
    expect($credential->token_hash)->toBe($originalTokenHash);
    expect($credential->status)->toBe(DeviceCredentialStatus::Active);
});

it('validates equipment asset association and nulls reference when equipment is deleted', function (): void {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);
    $project = Project::factory()->for($tenant)->create();
    $otherProject = Project::factory()->for($tenant)->create();

    $stationAsset = Asset::factory()->for($project)->ofType(AssetType::Station)->create();
    $otherProjectEquipment = Asset::factory()->for($otherProject)->ofType(AssetType::Equipment)->create();
    $validEquipment = Asset::factory()->for($project)->ofType(AssetType::Equipment)->create();

    $this->actingAs($owner)
        ->postJson("/api/tenants/$tenant->id/projects/$project->id/devices", [
            'name' => 'Invalid Non-Equipment Device',
            'code' => 'non-equip-01',
            'equipment_asset_id' => $stationAsset->getKey(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['equipment_asset_id']);

    $this->actingAs($owner)
        ->postJson("/api/tenants/$tenant->id/projects/$project->id/devices", [
            'name' => 'Cross Project Equipment Device',
            'code' => 'cross-proj-equip-01',
            'equipment_asset_id' => $otherProjectEquipment->getKey(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['equipment_asset_id']);

    $deviceResponse = $this->actingAs($owner)
        ->postJson("/api/tenants/$tenant->id/projects/$project->id/devices", [
            'name' => 'Valid Equipment Device',
            'code' => 'valid-equip-01',
            'equipment_asset_id' => $validEquipment->getKey(),
        ])
        ->assertCreated();

    $deviceId = (string) $deviceResponse->json('data.id');

    $this->actingAs($owner)
        ->deleteJson("/api/tenants/$tenant->id/projects/$project->id/assets/$validEquipment->id")
        ->assertNoContent();

    $this->actingAs($owner)
        ->getJson("/api/tenants/$tenant->id/projects/$project->id/devices/$deviceId")
        ->assertOk()
        ->assertJsonPath('data.equipment_asset_id', null);
});

it('allows project viewers and operators to read devices but forbids mutating or viewing credentials', function (string $role): void {
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $device = Device::factory()->for($project)->create();

    $user = User::factory()->create();
    $tenant->users()->attach($user, ['role' => 'member']);
    $project->users()->attach($user, ['role' => $role]);

    $this->actingAs($user)
        ->getJson("/api/tenants/$tenant->id/projects/$project->id/devices")
        ->assertOk()
        ->assertJsonPath('data.0.id', $device->getKey());

    $this->actingAs($user)
        ->getJson("/api/tenants/$tenant->id/projects/$project->id/devices/$device->id")
        ->assertOk();

    $this->actingAs($user)
        ->getJson("/api/tenants/$tenant->id/projects/$project->id/devices/$device->id/credentials")
        ->assertForbidden();

    $this->actingAs($user)
        ->postJson("/api/tenants/$tenant->id/projects/$project->id/devices/$device->id/credentials/rotate")
        ->assertForbidden();

    $this->actingAs($user)
        ->postJson("/api/tenants/$tenant->id/projects/$project->id/devices", [
            'name' => 'Unauthorized Device',
            'code' => 'unauth-dev',
        ])
        ->assertForbidden();
})->with(['viewer', 'operator']);

it('allows project engineers to manage devices and credentials but forbids deleting devices', function (): void {
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $device = Device::factory()->for($project)->create();

    $engineer = User::factory()->create();
    $tenant->users()->attach($engineer, ['role' => 'member']);
    $project->users()->attach($engineer, ['role' => 'engineer']);

    $this->actingAs($engineer)
        ->patchJson("/api/tenants/$tenant->id/projects/$project->id/devices/$device->id", [
            'name' => 'Engineer Updated Name',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Engineer Updated Name');

    $this->actingAs($engineer)
        ->getJson("/api/tenants/$tenant->id/projects/$project->id/devices/$device->id/credentials")
        ->assertOk();

    $this->actingAs($engineer)
        ->deleteJson("/api/tenants/$tenant->id/projects/$project->id/devices/$device->id")
        ->assertForbidden();
});

it('allows project admins to delete devices', function (): void {
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $device = Device::factory()->for($project)->create();

    $projectAdmin = User::factory()->create();
    $tenant->users()->attach($projectAdmin, ['role' => 'member']);
    $project->users()->attach($projectAdmin, ['role' => 'admin']);

    $this->actingAs($projectAdmin)
        ->deleteJson("/api/tenants/$tenant->id/projects/$project->id/devices/$device->id")
        ->assertNoContent();

    $this->assertDatabaseMissing('devices', ['id' => $device->getKey()]);
});

it('rotates, updates, and revokes device credentials while keeping mqtt views synchronized', function (): void {
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);
    $project = Project::factory()->for($tenant)->create();
    $device = Device::factory()->for($project)->create();

    $initialCredential = $this->actingAs($owner)
        ->getJson("/api/tenants/$tenant->id/projects/$project->id/devices/$device->id/credentials")
        ->assertOk();

    $initialToken = (string) $initialCredential->json('data.access_token');
    $initialHash = hash('sha256', $initialToken);
    expect(DB::table('v_mqtt_users')->where('token_hash', $initialHash)->exists())->toBeTrue();

    $rotatedResponse = $this->actingAs($owner)
        ->postJson("/api/tenants/$tenant->id/projects/$project->id/devices/$device->id/credentials/rotate")
        ->assertOk()
        ->assertJsonPath('data.status', DeviceCredentialStatus::Active->value);

    $rotatedToken = (string) $rotatedResponse->json('data.access_token');
    $rotatedHash = hash('sha256', $rotatedToken);

    expect($rotatedToken)->not->toBe($initialToken);
    expect(DB::table('v_mqtt_users')->where('token_hash', $initialHash)->exists())->toBeFalse();
    expect(DB::table('v_mqtt_users')->where('token_hash', $rotatedHash)->exists())->toBeTrue();

    $this->actingAs($owner)
        ->putJson("/api/tenants/$tenant->id/projects/$project->id/devices/$device->id/credentials", [
            'status' => DeviceCredentialStatus::Revoked->value,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', DeviceCredentialStatus::Revoked->value);

    expect(DB::table('v_mqtt_users')->where('token_hash', $rotatedHash)->exists())->toBeFalse();
    expect(DB::table('v_mqtt_acls')->where('token_hash', $rotatedHash)->exists())->toBeFalse();

    $this->actingAs($owner)
        ->putJson("/api/tenants/$tenant->id/projects/$project->id/devices/$device->id/credentials", [
            'status' => DeviceCredentialStatus::Active->value,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', DeviceCredentialStatus::Active->value)
        ->assertJsonPath('data.revoked_at', null);

    expect(DB::table('v_mqtt_users')->where('token_hash', $rotatedHash)->exists())->toBeTrue();

    $this->actingAs($owner)
        ->patchJson("/api/tenants/$tenant->id/projects/$project->id/devices/$device->id", [
            'status' => DeviceStatus::Inactive->value,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', DeviceStatus::Inactive->value);

    expect(DB::table('v_mqtt_users')->where('token_hash', $rotatedHash)->exists())->toBeFalse();
});

it('isolates devices across tenants and projects and supports tenant-level filtering', function (): void {
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $tenantA->users()->attach($ownerA, ['role' => 'owner']);
    $tenantB->users()->attach($ownerB, ['role' => 'owner']);

    $projectA1 = Project::factory()->for($tenantA)->create();
    $projectA2 = Project::factory()->for($tenantA)->create();
    $projectB1 = Project::factory()->for($tenantB)->create();

    $deviceA1 = Device::factory()->for($projectA1)->create([
        'name' => 'Alpha Boiler Node',
        'code' => 'alpha-boiler',
        'connection_state' => DeviceConnectionState::Online->value,
    ]);
    $deviceA2 = Device::factory()->for($projectA2)->create([
        'name' => 'Beta Chiller Node',
        'code' => 'beta-chiller',
        'connection_state' => DeviceConnectionState::Offline->value,
    ]);
    $deviceB1 = Device::factory()->for($projectB1)->create();

    $this->actingAs($ownerA)
        ->getJson("/api/tenants/$tenantB->id/projects/$projectB1->id/devices/$deviceB1->id")
        ->assertNotFound();

    $this->actingAs($ownerA)
        ->getJson("/api/tenants/$tenantA->id/projects/$projectA1->id/devices/$deviceA2->id")
        ->assertNotFound();

    $this->actingAs($ownerA)
        ->postJson("/api/tenants/$tenantA->id/projects/$projectA1->id/devices", [
            'name' => 'Prohibited Scope Device',
            'code' => 'prohibited-scope',
            'tenant_id' => $tenantB->id,
            'project_id' => $projectB1->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tenant_id', 'project_id']);

    $this->actingAs($ownerA)
        ->getJson("/api/tenants/$tenantA->id/devices?search=Alpha&connection_state=online")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $deviceA1->getKey());
});
