<?php

use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Services\Device\DeviceCredentialService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

function plaintextFixture(): array
{
    $owner = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($owner, ['role' => 'owner']);
    $project = Project::factory()->for($tenant)->create();
    $device = Device::factory()->for($project)->create()->refresh();

    return [$owner, $tenant, $project, $device];
}

it('lists the plaintext endpoint only for devices that opted in', function (): void {
    config()->set('device_provisioning.mqtt_tls_port', 18883);
    config()->set('device_provisioning.mqtt_plaintext_port', 11883);
    [, , , $device] = plaintextFixture();
    $service = app(DeviceCredentialService::class);

    expect($device->allow_plaintext)->toBeFalse()
        ->and($service->mqttEndpoints($device))->toBe([['port' => 18883, 'tls' => true]]);

    $device->update(['allow_plaintext' => true]);

    expect($service->mqttEndpoints($device))->toBe([
        ['port' => 18883, 'tls' => true],
        ['port' => 11883, 'tls' => false],
    ]);
});

it('lets a project manager switch plaintext access for one device only', function (): void {
    config()->set('device_provisioning.mqtt_host', 'mqtt.liteboard.test');
    [$owner, $tenant, $project, $device] = plaintextFixture();
    $other = Device::factory()->for($project)->create()->refresh();

    $this->actingAs($owner)
        ->patchJson("/api/tenants/$tenant->id/projects/$project->id/devices/$device->id", ['allow_plaintext' => true])
        ->assertOk()
        ->assertJsonPath('data.allow_plaintext', true);

    $this->actingAs($owner)
        ->getJson("/api/tenants/$tenant->id/projects/$project->id/devices/$device->id/credentials")
        ->assertOk()
        ->assertJsonPath('data.mqtt.endpoints', [['port' => 8883, 'tls' => true], ['port' => 1883, 'tls' => false]]);

    $this->actingAs($owner)
        ->getJson("/api/tenants/$tenant->id/projects/$project->id/devices/$other->id/credentials")
        ->assertOk()
        ->assertJsonPath('data.mqtt.endpoints', [['port' => 8883, 'tls' => true]]);

    $this->actingAs($owner)
        ->patchJson("/api/tenants/$tenant->id/projects/$project->id/devices/$device->id", ['allow_plaintext' => false])
        ->assertOk()
        ->assertJsonPath('data.allow_plaintext', false);
});

it('rejects invalid plaintext values and users outside the tenant', function (): void {
    [$owner, $tenant, $project, $device] = plaintextFixture();
    $stranger = User::factory()->create();

    $this->actingAs($owner)
        ->patchJson("/api/tenants/$tenant->id/projects/$project->id/devices/$device->id", ['allow_plaintext' => 'maybe'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('allow_plaintext');

    $this->actingAs($stranger)
        ->patchJson("/api/tenants/$tenant->id/projects/$project->id/devices/$device->id", ['allow_plaintext' => true])
        ->assertNotFound();

    expect($device->refresh()->allow_plaintext)->toBeFalse();
});
