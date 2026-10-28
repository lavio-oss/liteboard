<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

const GATEWAY_INTERNAL_SECRET = 'test-internal-secret-at-least-32-characters';

function gatewayFixture(): array
{
    config()->set('services.telemetry.internal_secret', GATEWAY_INTERNAL_SECRET);
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);
    $project = Project::factory()->for($tenant)->create();
    $gateway = Device::factory()->for($project)->gateway()->create()->refresh();

    return [$owner, $tenant, $project, $gateway];
}

function registerSubDevice(Device $gateway, string $code, string $secret = GATEWAY_INTERNAL_SECRET): TestResponse
{
    return test()->postJson(
        "/api/internal/gateways/$gateway->id/sub-devices",
        ['code' => $code],
        ['X-Internal-Token' => $secret],
    );
}

it('creates a gateway device and exposes its gateway topics', function (): void {
    [$owner, $tenant, $project] = gatewayFixture();
    $prefix = "liteboard/$project->id/devices";

    $response = $this->actingAs($owner)
        ->postJson("/api/tenants/$tenant->id/projects/$project->id/devices", [
            'name' => 'Line gateway',
            'code' => 'gw-1',
            'is_gateway' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.is_gateway', true)
        ->assertJsonPath('data.gateway_device_id', null);

    $id = $response->json('data.id');
    $response->assertJsonPath('data.mqtt_topics.gateway', [
        'connect' => "$prefix/$id/gateway/connect",
        'telemetry' => "$prefix/$id/gateway/telemetry",
        'disconnect' => "$prefix/$id/gateway/disconnect",
    ]);
});

it('registers a sub-device once per code without a credential', function (): void {
    [$owner, $tenant, $project, $gateway] = gatewayFixture();

    $id = registerSubDevice($gateway, 'sensor-a')->assertOk()->json('id');
    registerSubDevice($gateway, 'sensor-a')->assertOk()->assertJsonPath('id', $id);

    $subDevice = Device::query()->findOrFail($id);
    expect($subDevice->gateway_device_id)->toBe($gateway->id)
        ->and($subDevice->project_id)->toBe($project->id)
        ->and($subDevice->name)->toBe('sensor-a')
        ->and($subDevice->credential()->exists())->toBeFalse()
        ->and($subDevice->device_profile_version_id)->not->toBeNull();

    $this->actingAs($owner)
        ->getJson("/api/tenants/$tenant->id/projects/$project->id/devices/$id")
        ->assertOk()
        ->assertJsonPath('data.gateway_device_id', $gateway->id)
        ->assertJsonPath('data.approval_status', 'approved')
        ->assertJsonPath('data.credential_type', null)
        ->assertJsonPath('data.credential_status', null)
        ->assertJsonPath('data.mqtt_topics', null);

    $this->actingAs($owner)
        ->getJson("/api/tenants/$tenant->id/projects/$project->id/devices/$id/credentials")
        ->assertNotFound();
});

it('refuses a code owned by another device or gateway', function (): void {
    [, , $project, $gateway] = gatewayFixture();
    $direct = Device::factory()->for($project)->create(['code' => 'direct-1']);
    $otherGateway = Device::factory()->for($project)->gateway()->create()->refresh();
    Device::factory()->subDeviceOf($otherGateway)->create(['code' => 'theirs']);

    registerSubDevice($gateway, 'direct-1')->assertConflict()->assertJsonPath('code', 'GATEWAY_SUB_DEVICE_CODE_TAKEN');
    registerSubDevice($gateway, 'theirs')->assertConflict()->assertJsonPath('code', 'GATEWAY_SUB_DEVICE_CODE_TAKEN');

    expect($direct->refresh()->gateway_device_id)->toBeNull();
});

it('rejects a wrong secret, a non-gateway and an inactive gateway', function (): void {
    [, , $project, $gateway] = gatewayFixture();
    $plain = Device::factory()->for($project)->create();
    $inactive = Device::factory()->for($project)->gateway()->create(['status' => 'inactive']);

    registerSubDevice($gateway, 'sensor-a', 'wrong-secret-wrong-secret-wrong-secret')->assertForbidden();
    registerSubDevice($plain, 'sensor-a')->assertConflict()->assertJsonPath('code', 'GATEWAY_UNAVAILABLE');
    registerSubDevice($inactive, 'sensor-a')->assertConflict()->assertJsonPath('code', 'GATEWAY_UNAVAILABLE');

    expect(Device::query()->where('code', 'sensor-a')->exists())->toBeFalse();
});

it('counts sub-devices against the project device quota', function (): void {
    [, , $project, $gateway] = gatewayFixture();
    $project->forceFill(['max_devices_quota' => 1])->save();

    registerSubDevice($gateway, 'sensor-a')->assertConflict()->assertJsonPath('code', 'PROJECT_DEVICE_QUOTA_EXCEEDED');
});

it('guards the gateway flag on update', function (): void {
    [$owner, $tenant, $project, $gateway] = gatewayFixture();
    $subDevice = Device::factory()->subDeviceOf($gateway)->create();
    $url = "/api/tenants/$tenant->id/projects/$project->id/devices";

    $this->actingAs($owner)
        ->patchJson("$url/$gateway->id", ['is_gateway' => false])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('is_gateway');

    $this->actingAs($owner)
        ->patchJson("$url/$subDevice->id", ['is_gateway' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('is_gateway');

    $this->actingAs($owner)
        ->patchJson("$url/$subDevice->id", ['gateway_device_id' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('gateway_device_id');
});

it('grants gateway topics in the broker ACL only to gateway devices', function (): void {
    [, , $project, $gateway] = gatewayFixture();
    $plain = Device::factory()->for($project)->create();
    $subDevice = Device::factory()->subDeviceOf($gateway)->create();

    $topicsFor = static fn (Device $device): array => DB::table('v_mqtt_acls')
        ->where('token_hash', $device->credential()->value('token_hash'))
        ->pluck('topic')
        ->all();

    expect($topicsFor($gateway))->toContain("liteboard/$project->id/devices/$gateway->id/gateway/telemetry")
        ->and(collect($topicsFor($plain))->filter(fn (string $topic): bool => str_contains($topic, '/gateway/')))->toBeEmpty()
        ->and(DB::table('v_mqtt_users')->where('device_id', $subDevice->id)->exists())->toBeFalse();
});
