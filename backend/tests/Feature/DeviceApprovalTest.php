<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\DeviceProvisioning\ProjectProvisioningProfile;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

/** @return array{User, Tenant, Project, ProjectProvisioningProfile, string, User} */
function createApprovalContext(): array
{
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);
    $engineer = User::factory()->create();
    $tenant->users()->attach($engineer, ['role' => 'member']);
    $project = Project::factory()->for($tenant)->create();
    $project->users()->attach($engineer, ['role' => 'engineer']);
    $secret = Str::random(64);
    $profile = $project->provisioningProfile()->create([
        'enabled' => true,
        'provision_key' => Str::lower(Str::random(32)),
        'provision_secret_hash' => Hash::make($secret),
    ]);

    return [$owner, $tenant, $project, $profile, $secret, $engineer];
}

/** @return array{provision_key: string, provision_secret: string, device_name: string, device_code: string, request_id: string} */
function approvalBootstrapPayload(
    ProjectProvisioningProfile $profile,
    string $secret,
): array {
    return [
        'provision_key' => $profile->provision_key,
        'provision_secret' => $secret,
        'device_name' => 'Waiting Sensor',
        'device_code' => 'approval-sensor-01',
        'request_id' => (string) Str::uuid(),
    ];
}

it('issues the token immediately but leaves a provisioned device out of mqtt views until approval', function (): void {
    [, , $project, $profile, $secret] = createApprovalContext();
    config(['device_provisioning.mqtt_host' => 'mqtt.test.local']);
    $payload = approvalBootstrapPayload($profile, $secret);

    $response = $this->postJson('https://localhost/api/device-provisioning/bootstrap', $payload)
        ->assertCreated()
        ->assertJsonPath('provisioning.request_id', $payload['request_id'])
        ->assertJsonPath('provisioning.approval_status', 'pending');

    $deviceId = (string) $response->json('device.id');
    $token = (string) $response->json('credentials.access_token');
    $device = Device::query()->whereKey($deviceId)->firstOrFail();

    expect($token)->not->toBeEmpty()
        ->and($device->approval_status->value)->toBe('pending')
        ->and(DB::table('v_mqtt_users')->where('device_id', $deviceId)->exists())->toBeFalse()
        ->and(DB::table('v_mqtt_acls')->where('token_hash', $device->credential->token_hash)->exists())->toBeFalse();
});

it('lets a project engineer approve a pending device and records the decision', function (): void {
    [, $tenant, $project, $profile, $secret, $engineer] = createApprovalContext();
    config(['device_provisioning.mqtt_host' => 'mqtt.test.local']);
    $payload = approvalBootstrapPayload($profile, $secret);
    $bootstrap = $this->postJson('https://localhost/api/device-provisioning/bootstrap', $payload)
        ->assertCreated();
    $deviceId = (string) $bootstrap->json('device.id');
    $devicePath = "/api/tenants/$tenant->id/projects/$project->id/devices/$deviceId";

    $this->actingAs($engineer)
        ->postJson("$devicePath/approve")
        ->assertOk()
        ->assertJsonPath('data.approval_status', 'approved')
        ->assertJsonPath('data.approval_reason', null);

    $device = Device::query()->whereKey($deviceId)->firstOrFail();
    expect(DB::table('v_mqtt_users')->where('device_id', $deviceId)->exists())->toBeTrue();
    expect($project->provisioningAudits()->where('device_id', $deviceId)->firstOrFail())
        ->action->toBe('device_approved')
        ->actor_id->toBe((string) $engineer->getKey());

    $this->getJson('https://localhost/api/device-provisioning/status?request_id='.$payload['request_id'], [
        'X-Provision-Key' => $profile->provision_key,
        'X-Provision-Secret' => $secret,
    ])->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('approval_status', 'approved')
        ->assertJsonPath('rejection_reason', null)
        ->assertJsonMissingPath('credentials.access_token');

    $this->actingAs($engineer)
        ->postJson("$devicePath/approve")
        ->assertConflict()
        ->assertJsonPath('code', 'DEVICE_APPROVAL_ALREADY_DECIDED');
});

it('requires a rejection reason, returns it to firmware, and leaves the token blocked', function (): void {
    [, $tenant, $project, $profile, $secret, $engineer] = createApprovalContext();
    config(['device_provisioning.mqtt_host' => 'mqtt.test.local']);
    $payload = approvalBootstrapPayload($profile, $secret);
    $bootstrap = $this->postJson('https://localhost/api/device-provisioning/bootstrap', $payload)
        ->assertCreated();
    $deviceId = (string) $bootstrap->json('device.id');
    $devicePath = "/api/tenants/$tenant->id/projects/$project->id/devices/$deviceId";

    $this->actingAs($engineer)
        ->postJson("$devicePath/reject", ['reason' => 'Device identity could not be verified.'])
        ->assertOk()
        ->assertJsonPath('data.approval_status', 'rejected')
        ->assertJsonPath('data.approval_reason', 'Device identity could not be verified.');

    $device = Device::query()->whereKey($deviceId)->firstOrFail();
    expect(DB::table('v_mqtt_users')->where('device_id', $deviceId)->exists())->toBeFalse();
    expect($project->devices()->where('code', 'approval-sensor-01')->exists())->toBeTrue();
    expect($project->provisioningAudits()->where('device_id', $deviceId)->firstOrFail())
        ->action->toBe('device_rejected')
        ->reason->toBe('Device identity could not be verified.')
        ->actor_id->toBe((string) $engineer->getKey());

    $this->getJson('https://localhost/api/device-provisioning/status?request_id='.$payload['request_id'], [
        'X-Provision-Key' => $profile->provision_key,
        'X-Provision-Secret' => $secret,
    ])->assertOk()
        ->assertJsonPath('approval_status', 'rejected')
        ->assertJsonPath('rejection_reason', 'Device identity could not be verified.');

    $this->actingAs($engineer)
        ->postJson("$devicePath/reject", ['reason' => 'A second decision'])
        ->assertConflict()
        ->assertJsonPath('code', 'DEVICE_APPROVAL_ALREADY_DECIDED');

    $this->actingAs($engineer)
        ->postJson("$devicePath/approve")
        ->assertConflict()
        ->assertJsonPath('code', 'DEVICE_APPROVAL_ALREADY_DECIDED');

    expect($device->credential()->firstOrFail()->status->value)->toBe('active');
});

it('scopes approval and status checks to the owning tenant and blocks secret rotation while approval is pending', function (): void {
    [$owner, $tenant, $project, $profile, $secret] = createApprovalContext();
    config(['device_provisioning.mqtt_host' => 'mqtt.test.local']);
    $payload = approvalBootstrapPayload($profile, $secret);
    $bootstrap = $this->postJson('https://localhost/api/device-provisioning/bootstrap', $payload)
        ->assertCreated();
    $deviceId = (string) $bootstrap->json('device.id');

    $this->actingAs($owner)
        ->postJson("/api/tenants/$tenant->id/projects/$project->id/provisioning-profile/rotate-secret")
        ->assertConflict()
        ->assertJsonPath('code', 'PENDING_DEVICE_APPROVALS_EXIST');

    $otherTenant = Tenant::factory()->create();
    $outsider = User::factory()->create();
    $otherTenant->users()->attach($outsider, ['role' => 'owner']);

    $this->actingAs($outsider)
        ->postJson("/api/tenants/$tenant->id/projects/$project->id/devices/$deviceId/approve")
        ->assertNotFound();

    $profile->forceFill(['enabled' => false])->save();

    $this->getJson('https://localhost/api/device-provisioning/status?request_id='.$payload['request_id'], [
        'X-Provision-Key' => $profile->provision_key,
        'X-Provision-Secret' => $secret,
    ])->assertOk()
        ->assertJsonPath('approval_status', 'pending');

    $retryPayload = [...$payload, 'request_id' => (string) Str::uuid()];
    $this->postJson('https://localhost/api/device-provisioning/bootstrap', $retryPayload)
        ->assertForbidden()
        ->assertJsonPath('code', 'PROVISIONING_UNAVAILABLE');

    $this->getJson('https://localhost/api/device-provisioning/status?request_id='.$payload['request_id'], [
        'X-Provision-Key' => $profile->provision_key,
        'X-Provision-Secret' => 'invalid',
    ])->assertUnauthorized();

    $this->getJson('https://localhost/api/device-provisioning/status?request_id='.Str::uuid(), [
        'X-Provision-Key' => $profile->provision_key,
        'X-Provision-Secret' => $secret,
    ])->assertNotFound();
});

it('requires https for approval status reads outside local development', function (): void {
    config(['app.env' => 'testing']);

    $this->getJson('/api/device-provisioning/status?request_id='.Str::uuid(), [
        'X-Provision-Key' => 'key',
        'X-Provision-Secret' => 'secret',
    ])->assertStatus(426)
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('code', 'HTTPS_REQUIRED');
});

it('does not accept an empty rejection reason', function (): void {
    [, $tenant, $project, $profile, $secret, $engineer] = createApprovalContext();
    config(['device_provisioning.mqtt_host' => 'mqtt.test.local']);
    $bootstrap = $this->postJson(
        'https://localhost/api/device-provisioning/bootstrap',
        approvalBootstrapPayload($profile, $secret),
    )->assertCreated();
    $deviceId = (string) $bootstrap->json('device.id');

    $this->actingAs($engineer)
        ->postJson("/api/tenants/$tenant->id/projects/$project->id/devices/$deviceId/reject", ['reason' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason']);
});
