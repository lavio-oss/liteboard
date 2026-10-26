<?php

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\DeviceProvisioning\DeviceProvisioningReceipt;
use Modules\Admin\Models\DeviceProvisioning\ProjectProvisioningProfile;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Services\Device\DeviceCredentialService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

function createProvisioningProject(string $status = 'active', bool $createProfile = true): array
{
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create(['status' => $status]);
    $tenant->users()->attach($user, ['role' => 'owner']);
    $project = Project::factory()->for($tenant)->create();
    $secret = Str::random(64);
    $profile = $createProfile ? $project->provisioningProfile()->create([
        'enabled' => true,
        'provision_key' => Str::lower(Str::random(32)),
        'provision_secret_hash' => Hash::make($secret),
    ]) : null;

    return [$user, $tenant, $project, $profile, $secret];
}

function bootstrapPayload(ProjectProvisioningProfile $profile, string $secret, string $code = 'FACTORY-SERIAL-001'): array
{
    return [
        'provision_key' => $profile->provision_key,
        'provision_secret' => $secret,
        'device_name' => 'Pump sensor 01',
        'device_code' => $code,
        'request_id' => (string) Str::uuid(),
        'firmware_version' => '1.0.0',
    ];
}

it('creates a project provisioning profile that shows its secret only once and enforces project permissions', function (): void {
    [$owner, $tenant, $project] = createProvisioningProject('active', false);
    $engineer = User::factory()->create();
    $tenant->users()->attach($engineer, ['role' => 'member']);
    $project->users()->attach($engineer, ['role' => 'engineer']);

    $created = $this->actingAs($engineer)
        ->postJson("/api/tenants/$tenant->id/projects/$project->id/provisioning-profile")
        ->assertCreated()
        ->assertJsonPath('data.enabled', false);

    $secret = (string) $created->json('provision_secret');
    $profile = $project->provisioningProfile()->firstOrFail();

    expect($secret)->not->toBe($profile->provision_secret_hash);
    expect(Hash::check($secret, $profile->provision_secret_hash))->toBeTrue();

    $this->actingAs($engineer)
        ->getJson("/api/tenants/$tenant->id/projects/$project->id/provisioning-profile")
        ->assertOk()
        ->assertJsonMissingPath('provision_secret')
        ->assertJsonMissingPath('data.provision_secret_hash');

});

it('bootstraps a device atomically and returns the same active credential for an identical retry', function (): void {
    [, , $project, $profile, $secret] = createProvisioningProject();
    config(['device_provisioning.mqtt_host' => 'mqtt.test.local']);
    $payload = bootstrapPayload($profile, $secret);

    $first = $this->postJson('https://localhost/api/device-provisioning/bootstrap', $payload)
        ->assertCreated()
        ->assertHeaderContains('Cache-Control', 'no-store')
        ->assertJsonPath('credential_type', 'access_token')
        ->assertJsonPath('mqtt.host', 'mqtt.test.local')
        ->assertJsonPath('mqtt.endpoints', [['port' => 8883, 'tls' => true]])
        ->assertJsonMissingPath('mqtt.port')
        ->assertJsonMissingPath('mqtt.tls')
        ->assertJsonMissingPath('mqtt.plaintext_port')
        ->assertJsonPath('device.project_id', $project->getKey());

    $deviceId = (string) $first->json('device.id');
    $token = (string) $first->json('credentials.access_token');
    expect(Device::query()->where('project_id', $project->getKey())->count())->toBe(1);

    $this->postJson('https://localhost/api/device-provisioning/bootstrap', $payload)
        ->assertOk()
        ->assertJsonPath('device.id', $deviceId)
        ->assertJsonPath('credentials.access_token', $token);

    $this->postJson('https://localhost/api/device-provisioning/bootstrap', [
        ...$payload,
        'device_name' => 'Different name',
    ])->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
});

it('rejects invalid, disabled, cross-tenant and replayed provisioning requests without partial devices', function (): void {
    [, $tenant, $project, $profile, $secret] = createProvisioningProject();
    config(['device_provisioning.mqtt_host' => 'mqtt.test.local']);
    $payload = bootstrapPayload($profile, $secret);

    $this->postJson('https://localhost/api/device-provisioning/bootstrap', [...$payload, 'provision_secret' => 'invalid'])
        ->assertUnauthorized()
        ->assertJsonPath('code', 'INVALID_PROVISIONING_CREDENTIALS');

    $profile->forceFill(['enabled' => false])->save();
    $this->postJson('https://localhost/api/device-provisioning/bootstrap', $payload)
        ->assertForbidden()
        ->assertJsonPath('code', 'PROVISIONING_UNAVAILABLE');
    expect(Device::query()->where('project_id', $project->getKey())->count())->toBe(0);

    $profile->forceFill(['enabled' => true])->save();
    $project->forceFill(['status' => 'inactive'])->save();
    $this->postJson('https://localhost/api/device-provisioning/bootstrap', $payload)
        ->assertForbidden()
        ->assertJsonPath('code', 'PROVISIONING_UNAVAILABLE');
    $project->forceFill(['status' => 'active'])->save();

    $tenant->forceFill(['status' => 'inactive'])->save();
    $this->postJson('https://localhost/api/device-provisioning/bootstrap', $payload)
        ->assertForbidden()
        ->assertJsonPath('code', 'PROVISIONING_UNAVAILABLE');
    $tenant->forceFill(['status' => 'active'])->save();

    $this->postJson('https://localhost/api/device-provisioning/bootstrap', [...$payload, 'project_id' => (string) Str::uuid()])
        ->assertUnprocessable();
    $this->postJson('https://localhost/api/device-provisioning/bootstrap', [...$payload, 'credential_type' => 'mqtt_basic'])
        ->assertUnprocessable();

    expect(Device::query()->where('project_id', $project->getKey())->count())->toBe(0);
});

it('rejects bootstrap over plaintext HTTP', function (): void {
    [, , $project, $profile, $secret] = createProvisioningProject();
    config([
        'app.env' => 'testing',
        'device_provisioning.allow_insecure_http' => true,
        'device_provisioning.mqtt_host' => 'mqtt.test.local',
    ]);

    $this->postJson('/api/device-provisioning/bootstrap', bootstrapPayload($profile, $secret))
        ->assertStatus(426)
        ->assertJsonPath('code', 'HTTPS_REQUIRED');
    expect(Device::query()->where('project_id', $project->getKey())->count())->toBe(0);
});

it('allows plaintext bootstrap only when explicitly enabled in local environment', function (): void {
    [, , , $profile, $secret] = createProvisioningProject();
    config([
        'app.env' => 'local',
        'device_provisioning.allow_insecure_http' => true,
        'device_provisioning.mqtt_host' => 'mqtt.test.local',
    ]);

    $this->postJson('/api/device-provisioning/bootstrap', bootstrapPayload($profile, $secret))
        ->assertCreated()
        ->assertJsonPath('mqtt.endpoints.0', ['port' => 8883, 'tls' => true]);
});

it('applies project quota to manual creation and provisioning and prevents lowering it below device count', function (): void {
    [$owner, $tenant, $project, $profile, $secret] = createProvisioningProject();
    config(['device_provisioning.mqtt_host' => 'mqtt.test.local']);

    $this->actingAs($owner)
        ->putJson("/api/tenants/$tenant->id/projects/$project->id/device-quota", ['max_devices_quota' => 1])
        ->assertOk()
        ->assertJsonPath('data.max_devices_quota', 1);

    $payload = bootstrapPayload($profile, $secret);
    $this->postJson('https://localhost/api/device-provisioning/bootstrap', $payload)
        ->assertCreated();

    $this->actingAs($owner)
        ->postJson("/api/tenants/$tenant->id/projects/$project->id/devices", [
            'name' => 'Manual device',
            'code' => 'manual-01',
        ])
        ->assertConflict()
        ->assertJsonPath('code', 'PROJECT_DEVICE_QUOTA_EXCEEDED');

    $this->actingAs($owner)
        ->putJson("/api/tenants/$tenant->id/projects/$project->id/device-quota", ['max_devices_quota' => 0])
        ->assertUnprocessable();
});

it('does not allow users from another tenant to manage a project provisioning profile', function (): void {
    [, $tenant, $project] = createProvisioningProject();
    $otherTenant = Tenant::factory()->create();
    $outsider = User::factory()->create();
    $otherTenant->users()->attach($outsider, ['role' => 'owner']);

    $this->actingAs($outsider)
        ->postJson("/api/tenants/$tenant->id/projects/$project->id/provisioning-profile")
        ->assertNotFound();
});

it('refuses provisioning retries after credential rotation or device deletion', function (): void {
    [, , $project, $profile, $secret] = createProvisioningProject();
    config(['device_provisioning.mqtt_host' => 'mqtt.test.local']);
    $payload = bootstrapPayload($profile, $secret);

    $first = $this->postJson('https://localhost/api/device-provisioning/bootstrap', $payload)->assertCreated();
    $device = Device::query()->where('project_id', $project->getKey())->firstOrFail();
    app(DeviceCredentialService::class)->rotateCredential($device);

    $this->postJson('https://localhost/api/device-provisioning/bootstrap', $payload)
        ->assertConflict()
        ->assertJsonPath('code', 'PROVISIONING_CREDENTIAL_UNAVAILABLE');

    $device->delete();
    $this->postJson('https://localhost/api/device-provisioning/bootstrap', $payload)
        ->assertConflict()
        ->assertJsonPath('code', 'PROVISIONING_CREDENTIAL_UNAVAILABLE');
    expect(DeviceProvisioningReceipt::query()->where('profile_id', $profile->getKey())->count())->toBe(1);
    expect(DeviceProvisioningReceipt::query()->where('profile_id', $profile->getKey())->firstOrFail()->device_id)->toBeNull();

    expect($first->json('credentials.access_token'))->not->toBeEmpty();
});

it('rotates profile secrets and returns the new value only once', function (): void {
    [$owner, $tenant, $project, $profile, $secret] = createProvisioningProject();

    $rotated = $this->actingAs($owner)
        ->postJson("/api/tenants/$tenant->id/projects/$project->id/provisioning-profile/rotate-secret")
        ->assertOk()
        ->assertHeaderContains('Cache-Control', 'no-store');
    $newSecret = (string) $rotated->json('provision_secret');
    expect($newSecret)->not->toBe($secret);
    expect(Hash::check($newSecret, $profile->refresh()->provision_secret_hash))->toBeTrue();
});

it('rate limits authenticated provisioning profiles and returns Retry-After', function (): void {
    [, , , $profile, $secret] = createProvisioningProject();
    config([
        'device_provisioning.mqtt_host' => 'mqtt.test.local',
        'device_provisioning.rate_limits.profile_per_minute' => 1,
    ]);
    RateLimiter::clear('device-provisioning-profile:'.$profile->getKey());
    $payload = bootstrapPayload($profile, $secret);

    $this->postJson('https://localhost/api/device-provisioning/bootstrap', $payload)->assertCreated();
    $this->postJson('https://localhost/api/device-provisioning/bootstrap', [
        ...$payload,
        'request_id' => (string) Str::uuid(),
        'device_code' => 'FACTORY-SERIAL-002',
    ])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'PROVISIONING_RATE_LIMITED');
});
