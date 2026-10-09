<?php

use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Admin\Models\DeviceProfile\DeviceProfileVersion;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

/**
 * @return array{
 *     strict_mode: bool,
 *     fields: array{temperature: array{type: string, unit: string, required: bool, min: int, max: int}}
 * }
 */
function deviceProfileDefinition(): array
{
    return [
        'strict_mode' => true,
        'fields' => [
            'temperature' => [
                'type' => 'float',
                'unit' => 'celsius',
                'required' => true,
                'min' => -20,
                'max' => 150,
            ],
        ],
    ];
}

/** @return array{User, Tenant, Project} */
function createDeviceProfileOwner(): array
{
    $user = User::factory()->create();
    $tenant = Tenant::factory()->create();
    $tenant->users()->attach($user, ['role' => 'owner']);
    $project = Project::factory()->for($tenant)->create();

    return [$user, $tenant, $project];
}

it('creates, reads, updates and deletes project device profiles through the API', function (): void {
    [$owner, $tenant, $project] = createDeviceProfileOwner();
    $baseUrl = "/api/tenants/$tenant->id/projects/$project->id/device-profiles";

    $this->actingAs($owner)
        ->getJson($baseUrl)
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $created = $this->actingAs($owner)
        ->postJson($baseUrl, [
            'code' => 'motor',
            'name' => 'Motor telemetry',
            'description' => 'Schema for motor devices.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.project_id', $project->getKey())
        ->assertJsonPath('data.code', 'motor')
        ->assertJsonPath('data.name', 'Motor telemetry')
        ->assertJsonPath('data.versions', []);

    $profileId = (string) $created->json('data.id');

    $this->actingAs($owner)
        ->getJson("$baseUrl/$profileId")
        ->assertOk()
        ->assertJsonPath('data.id', $profileId);

    $this->actingAs($owner)
        ->patchJson("$baseUrl/$profileId", [
            'name' => 'Motor schema',
            'description' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Motor schema')
        ->assertJsonPath('data.description', null);

    $this->actingAs($owner)
        ->deleteJson("$baseUrl/$profileId")
        ->assertNoContent();

    $this->assertDatabaseMissing('device_profiles', ['id' => $profileId]);
});

it('creates immutable schema versions and keeps each device pinned to its selected version', function (): void {
    [$owner, $tenant, $project] = createDeviceProfileOwner();
    $profile = DeviceProfile::factory()->for($project)->create(['code' => 'motor']);
    $versionsUrl = "/api/tenants/$tenant->id/projects/$project->id/device-profiles/$profile->id/versions";

    $versionOneResponse = $this->actingAs($owner)
        ->postJson($versionsUrl, [
            'schema_code' => 'motor-v1',
            'definition' => deviceProfileDefinition(),
            'compatibility_note' => 'Initial release',
        ])
        ->assertCreated()
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.schema_code', 'motor-v1')
        ->assertJsonPath('data.definition.strict_mode', true)
        ->assertJsonPath('data.compatibility_note', 'Initial release');
    $versionOneId = (string) $versionOneResponse->json('data.id');

    $createdDevice = $this->actingAs($owner)
        ->postJson("/api/tenants/$tenant->id/projects/$project->id/devices", [
            'name' => 'Profile assigned device',
            'code' => 'profile-assigned-device',
            'device_profile_version_id' => $versionOneId,
        ])
        ->assertCreated()
        ->assertJsonPath('data.device_profile_version_id', $versionOneId);
    $createdDeviceId = (string) $createdDevice->json('data.id');

    $device = Device::factory()->for($project)->create();
    $this->actingAs($owner)
        ->patchJson(
            "/api/tenants/$tenant->id/projects/$project->id/devices/$device->id",
            ['device_profile_version_id' => $versionOneId],
        )
        ->assertOk()
        ->assertJsonPath('data.device_profile_version_id', $versionOneId);

    $versionTwoResponse = $this->actingAs($owner)
        ->postJson($versionsUrl, [
            'schema_code' => 'motor-v2',
            'definition' => [
                'strict_mode' => false,
                'fields' => [
                    'temperature' => [
                        'type' => 'float',
                        'unit' => 'celsius',
                        'required' => true,
                    ],
                    'rpm' => [
                        'type' => 'integer',
                        'unit' => 'rpm',
                        'required' => true,
                        'min' => 0,
                    ],
                ],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.version', 2);

    $this->actingAs($owner)
        ->getJson("/api/tenants/$tenant->id/projects/$project->id/device-profiles/$profile->id")
        ->assertOk()
        ->assertJsonCount(2, 'data.versions')
        ->assertJsonPath('data.versions.0.id', $versionOneId)
        ->assertJsonPath('data.versions.1.id', $versionTwoResponse->json('data.id'));

    expect($device->fresh()->getAttribute('device_profile_version_id'))->toBe($versionOneId);
    expect(Device::query()->findOrFail($createdDeviceId)->getAttribute('device_profile_version_id'))
        ->toBe($versionOneId);
    expect(DeviceProfileVersion::query()->findOrFail($versionOneId)->definition)
        ->toBe(deviceProfileDefinition());
});

it('rejects duplicate profile codes, schema codes, and invalid field ranges', function (): void {
    [$owner, $tenant, $project] = createDeviceProfileOwner();
    $baseUrl = "/api/tenants/$tenant->id/projects/$project->id/device-profiles";
    $firstProfile = DeviceProfile::factory()->for($project)->create(['code' => 'motor']);
    $secondProfile = DeviceProfile::factory()->for($project)->create(['code' => 'pump']);

    $this->actingAs($owner)
        ->postJson($baseUrl, [
            'code' => 'motor',
            'name' => 'Duplicate profile',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    $this->actingAs($owner)
        ->postJson("$baseUrl/$firstProfile->id/versions", [
            'schema_code' => 'shared-v1',
            'definition' => deviceProfileDefinition(),
        ])
        ->assertCreated();

    $this->actingAs($owner)
        ->postJson("$baseUrl/$secondProfile->id/versions", [
            'schema_code' => 'shared-v1',
            'definition' => deviceProfileDefinition(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['schema_code']);

    $this->actingAs($owner)
        ->postJson("$baseUrl/$secondProfile->id/versions", [
            'schema_code' => 'invalid-range-v1',
            'definition' => [
                'strict_mode' => true,
                'fields' => [
                    'temperature' => [
                        'type' => 'float',
                        'unit' => 'celsius',
                        'required' => true,
                        'min' => 10,
                        'max' => 0,
                    ],
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['definition.fields.temperature.max']);

    $this->actingAs($owner)
        ->postJson("$baseUrl/$secondProfile->id/versions", [
            'schema_code' => 'invalid-type-v1',
            'definition' => [
                'strict_mode' => true,
                'fields' => [
                    'temperature' => [
                        'type' => 'decimal',
                        'unit' => 'celsius',
                        'required' => true,
                    ],
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['definition.fields.temperature.type']);
});

it('stores enumerated telemetry states on string fields', function (): void {
    [$owner, $tenant, $project] = createDeviceProfileOwner();
    $profile = DeviceProfile::factory()->for($project)->create(['code' => 'motor']);
    $versionsUrl = "/api/tenants/$tenant->id/projects/$project->id/device-profiles/$profile->id/versions";

    $response = $this->actingAs($owner)
        ->postJson($versionsUrl, [
            'schema_code' => 'motor-state-v1',
            'definition' => [
                'strict_mode' => true,
                'fields' => [
                    'state' => [
                        'type' => 'string',
                        'unit' => 'code',
                        'required' => true,
                        'allowed_values' => ['running', 'stopped', 'fault'],
                    ],
                ],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.definition.fields.state.allowed_values', ['running', 'stopped', 'fault']);

    $versionId = (string) $response->json('data.id');
    $version = DeviceProfileVersion::query()->findOrFail($versionId);
    expect($version->definition['fields']['state']['allowed_values'])
        ->toBe(['running', 'stopped', 'fault']);
});

it('returns 422 when telemetry states are declared for a non-string field', function (): void {
    [$owner, $tenant, $project] = createDeviceProfileOwner();
    $profile = DeviceProfile::factory()->for($project)->create(['code' => 'motor']);
    $versionsUrl = "/api/tenants/$tenant->id/projects/$project->id/device-profiles/$profile->id/versions";

    $this->actingAs($owner)
        ->postJson($versionsUrl, [
            'schema_code' => 'motor-invalid-state-type-v1',
            'definition' => [
                'strict_mode' => true,
                'fields' => [
                    'temperature' => [
                        'type' => 'float',
                        'unit' => 'celsius',
                        'required' => true,
                        'allowed_values' => ['hot', 'cold'],
                    ],
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['definition.fields.temperature.allowed_values']);

    $this->assertDatabaseMissing('device_profile_versions', [
        'device_profile_id' => $profile->getKey(),
        'schema_code' => 'motor-invalid-state-type-v1',
    ]);
});

it('returns 422 when telemetry state values are duplicated', function (): void {
    [$owner, $tenant, $project] = createDeviceProfileOwner();
    $profile = DeviceProfile::factory()->for($project)->create(['code' => 'motor']);
    $versionsUrl = "/api/tenants/$tenant->id/projects/$project->id/device-profiles/$profile->id/versions";

    $this->actingAs($owner)
        ->postJson($versionsUrl, [
            'schema_code' => 'motor-duplicate-states-v1',
            'definition' => [
                'strict_mode' => true,
                'fields' => [
                    'state' => [
                        'type' => 'string',
                        'unit' => 'code',
                        'required' => true,
                        'allowed_values' => ['running', 'running'],
                    ],
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['definition.fields.state.allowed_values']);

    $this->assertDatabaseMissing('device_profile_versions', [
        'device_profile_id' => $profile->getKey(),
        'schema_code' => 'motor-duplicate-states-v1',
    ]);
});

it('rejects unauthenticated device profile API access', function (): void {
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $baseUrl = "/api/tenants/$tenant->id/projects/$project->id/device-profiles";

    $this->getJson($baseUrl)->assertUnauthorized();
    $this->postJson($baseUrl, ['code' => 'motor', 'name' => 'Motor profile'])
        ->assertUnauthorized();
});

it('rejects cross-project device assignments and protects assigned profiles from deletion', function (): void {
    [$owner, $tenant, $project] = createDeviceProfileOwner();
    $otherProject = Project::factory()->for($tenant)->create();
    $profile = DeviceProfile::factory()->for($project)->create();
    $otherProfile = DeviceProfile::factory()->for($otherProject)->create();
    $version = DeviceProfileVersion::factory()->forProfile($otherProfile)->create();
    $device = Device::factory()->for($project)->create();

    $this->actingAs($owner)
        ->patchJson(
            "/api/tenants/$tenant->id/projects/$project->id/devices/$device->id",
            ['device_profile_version_id' => $version->getKey()],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['device_profile_version_id']);

    $assignedVersion = DeviceProfileVersion::factory()->forProfile($profile)->create();
    $this->actingAs($owner)
        ->patchJson(
            "/api/tenants/$tenant->id/projects/$project->id/devices/$device->id",
            ['device_profile_version_id' => $assignedVersion->getKey()],
        )
        ->assertOk();

    $this->actingAs($owner)
        ->deleteJson("/api/tenants/$tenant->id/projects/$project->id/device-profiles/$profile->id")
        ->assertStatus(409);

    $this->actingAs($owner)
        ->patchJson(
            "/api/tenants/$tenant->id/projects/$project->id/devices/$device->id",
            ['device_profile_version_id' => null],
        )
        ->assertOk()
        ->assertJsonPath('data.device_profile_version_id', null);

    $this->actingAs($owner)
        ->deleteJson("/api/tenants/$tenant->id/projects/$project->id/device-profiles/$profile->id")
        ->assertNoContent();

    $this->assertDatabaseMissing('device_profiles', ['id' => $profile->getKey()]);
    $this->assertDatabaseMissing('device_profile_versions', ['id' => $assignedVersion->getKey()]);
});

it('keeps device profile APIs inside the active tenant', function (): void {
    [$owner, $tenant, $project] = createDeviceProfileOwner();
    [, $otherTenant, $otherProject] = createDeviceProfileOwner();
    $profile = DeviceProfile::factory()->for($otherProject)->create();

    $this->actingAs($owner)
        ->getJson("/api/tenants/$otherTenant->id/projects/$otherProject->id/device-profiles/$profile->id")
        ->assertNotFound();
});

it('enforces project write permissions for profile mutations', function (): void {
    [$owner, $tenant, $project] = createDeviceProfileOwner();
    $member = User::factory()->create();
    $tenant->users()->attach($member, ['role' => 'member']);
    $project->users()->attach($member, ['role' => 'viewer']);

    $this->actingAs($member)
        ->postJson("/api/tenants/$tenant->id/projects/$project->id/device-profiles", [
            'code' => 'blocked',
            'name' => 'Blocked profile',
        ])
        ->assertForbidden();
});
