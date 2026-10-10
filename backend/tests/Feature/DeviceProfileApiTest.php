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

it('lets project viewers read profiles but not change them or their versions', function (): void {
    [, $tenant, $project] = createDeviceProfileOwner();
    $viewer = User::factory()->create();
    $tenant->users()->attach($viewer, ['role' => 'member']);
    $project->users()->attach($viewer, ['role' => 'viewer']);
    $profile = DeviceProfile::factory()->for($project)->create(['name' => 'Motor telemetry']);
    $baseUrl = "/api/tenants/$tenant->id/projects/$project->id/device-profiles";

    $this->actingAs($viewer)
        ->getJson($baseUrl)
        ->assertOk()
        ->assertJsonPath('data.0.id', $profile->getKey());

    $this->actingAs($viewer)
        ->getJson("$baseUrl/$profile->id")
        ->assertOk();

    $this->actingAs($viewer)
        ->postJson("$baseUrl/$profile->id/versions", [
            'schema_code' => 'motor-v1',
            'definition' => deviceProfileDefinition(),
        ])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->patchJson("$baseUrl/$profile->id", ['name' => 'Renamed'])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->deleteJson("$baseUrl/$profile->id")
        ->assertForbidden();

    expect($profile->fresh()?->getAttribute('name'))->toBe('Motor telemetry');
    $this->assertDatabaseMissing('device_profile_versions', ['device_profile_id' => $profile->getKey()]);
});

it('hides project profiles from tenant members outside the project', function (): void {
    [, $tenant, $project] = createDeviceProfileOwner();
    $outsider = User::factory()->create();
    $tenant->users()->attach($outsider, ['role' => 'member']);

    $this->actingAs($outsider)
        ->getJson("/api/tenants/$tenant->id/projects/$project->id/device-profiles")
        ->assertForbidden();
});

it('rejects server-owned and malformed schema version input', function (array $payload, array $errors): void {
    [$owner, $tenant, $project] = createDeviceProfileOwner();
    $profile = DeviceProfile::factory()->for($project)->create();

    $this->actingAs($owner)
        ->postJson(
            "/api/tenants/$tenant->id/projects/$project->id/device-profiles/$profile->id/versions",
            [
                'schema_code' => 'motor-v1',
                'definition' => deviceProfileDefinition(),
                ...$payload,
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);

    $this->assertDatabaseMissing('device_profile_versions', ['device_profile_id' => $profile->getKey()]);
})->with([
    'version' => [['version' => 7], ['version']],
    'project_id' => [['project_id' => '00000000-0000-0000-0000-000000000000'], ['project_id']],
    'tenant_id' => [['tenant_id' => '00000000-0000-0000-0000-000000000000'], ['tenant_id']],
    'device_profile_id' => [['device_profile_id' => '00000000-0000-0000-0000-000000000000'], ['device_profile_id']],
    'missing strict_mode' => [
        ['definition' => ['fields' => deviceProfileDefinition()['fields']]],
        ['definition.strict_mode'],
    ],
    'empty fields' => [['definition' => ['strict_mode' => true, 'fields' => []]], ['definition.fields']],
    'unknown field key' => [
        ['definition' => ['strict_mode' => true, 'fields' => [
            'temperature' => ['type' => 'float', 'unit' => 'celsius', 'required' => true, 'precision' => 2],
        ]]],
        ['definition.fields.temperature'],
    ],
    'field name starting with a digit' => [
        ['definition' => ['strict_mode' => true, 'fields' => [
            '1temp' => ['type' => 'float', 'unit' => 'celsius', 'required' => true],
        ]]],
        ['definition.fields'],
    ],
    'field name with a dash' => [
        ['definition' => ['strict_mode' => true, 'fields' => [
            'temp-c' => ['type' => 'float', 'unit' => 'celsius', 'required' => true],
        ]]],
        ['definition.fields'],
    ],
    'range on a string field' => [
        ['definition' => ['strict_mode' => true, 'fields' => [
            'label' => ['type' => 'string', 'unit' => 'text', 'required' => true, 'min' => 1],
        ]]],
        ['definition.fields.label'],
    ],
    'range on a boolean field' => [
        ['definition' => ['strict_mode' => true, 'fields' => [
            'running' => ['type' => 'boolean', 'unit' => 'flag', 'required' => true, 'max' => 1],
        ]]],
        ['definition.fields.running'],
    ],
    'compatibility note too long' => [['compatibility_note' => str_repeat('a', 1001)], ['compatibility_note']],
]);

it('scopes profile routes to the project in the URL', function (): void {
    [$owner, $tenant, $project] = createDeviceProfileOwner();
    $otherProject = Project::factory()->for($tenant)->create();
    $otherProfile = DeviceProfile::factory()->for($otherProject)->create();
    $baseUrl = "/api/tenants/$tenant->id/projects/$project->id/device-profiles";

    $this->actingAs($owner)->getJson("$baseUrl/$otherProfile->id")->assertNotFound();
    $this->actingAs($owner)->patchJson("$baseUrl/$otherProfile->id", ['name' => 'Moved'])->assertNotFound();
    $this->actingAs($owner)
        ->postJson("$baseUrl/$otherProfile->id/versions", [
            'schema_code' => 'motor-v1',
            'definition' => deviceProfileDefinition(),
        ])
        ->assertNotFound();
});

it('rejects creating a device with a profile version from another project', function (): void {
    [$owner, $tenant, $project] = createDeviceProfileOwner();
    $otherProject = Project::factory()->for($tenant)->create();
    $otherVersion = DeviceProfileVersion::factory()
        ->forProfile(DeviceProfile::factory()->for($otherProject)->create())
        ->create();

    $this->actingAs($owner)
        ->postJson("/api/tenants/$tenant->id/projects/$project->id/devices", [
            'name' => 'Cross project device',
            'code' => 'cross-project-device',
            'device_profile_version_id' => $otherVersion->getKey(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['device_profile_version_id']);

    $this->assertDatabaseMissing('devices', ['code' => 'cross-project-device']);
});

it('exposes no endpoint for changing a published schema version', function (): void {
    [$owner, $tenant, $project] = createDeviceProfileOwner();
    $profile = DeviceProfile::factory()->for($project)->create();
    $version = DeviceProfileVersion::factory()->forProfile($profile)->create();
    $versionUrl = "/api/tenants/$tenant->id/projects/$project->id/device-profiles/$profile->id/versions/$version->id";

    expect($this->actingAs($owner)->patchJson($versionUrl, ['schema_code' => 'changed'])->status())
        ->toBeIn([404, 405]);
    expect($this->actingAs($owner)->deleteJson($versionUrl)->status())
        ->toBeIn([404, 405]);

    expect($version->fresh()?->getAttribute('schema_code'))->toBe($version->getAttribute('schema_code'));
});

it('allows the same profile code and schema code in different projects', function (): void {
    [$owner, $tenant, $project] = createDeviceProfileOwner();
    $otherProject = Project::factory()->for($tenant)->create();

    foreach ([$project, $otherProject] as $targetProject) {
        $baseUrl = "/api/tenants/$tenant->id/projects/$targetProject->id/device-profiles";
        $profileId = (string) $this->actingAs($owner)
            ->postJson($baseUrl, ['code' => 'motor', 'name' => 'Motor telemetry'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($owner)
            ->postJson("$baseUrl/$profileId/versions", [
                'schema_code' => 'motor-v1',
                'definition' => deviceProfileDefinition(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.version', 1);
    }
});
