<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Admin\Models\DeviceProfile\DeviceProfileVersion;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\Tenant\Tenant;

it('creates device profiles with the defined schema columns', function (): void {
    expect(Schema::getColumnListing('device_profiles'))->toEqualCanonicalizing([
        'id',
        'project_id',
        'code',
        'name',
        'description',
        'created_at',
        'updated_at',
    ]);
});

it('stores versioned schema definitions and leaves assigned devices pinned', function (): void {
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $profile = DeviceProfile::factory()->for($project)->create(['code' => 'motor']);
    $versionOneDefinition = [
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
    $versionOne = DeviceProfileVersion::factory()
        ->forProfile($profile)
        ->create([
            'version' => 1,
            'schema_code' => 'motor-v1',
            'definition' => $versionOneDefinition,
        ]);

    $device = Device::factory()->for($project)->create();
    expect($device->getAttribute('device_profile_version_id'))->toBeNull();

    $device->profileVersion()->associate($versionOne);
    $device->save();

    $versionTwo = DeviceProfileVersion::factory()
        ->forProfile($profile)
        ->create([
            'version' => 2,
            'schema_code' => 'motor-v2',
            'definition' => ['strict_mode' => false, 'fields' => []],
        ]);

    expect(DeviceProfileVersion::query()->findOrFail($versionOne->getKey())->definition)
        ->toBe($versionOneDefinition);
    expect($device->fresh()->profileVersion()->firstOrFail()->getKey())
        ->toBe($versionOne->getKey());
    expect($project->deviceProfiles()->sole()->getKey())->toBe($profile->getKey());
    expect($versionTwo->version)->toBe(2);
});

it('scopes profile codes to their project', function (): void {
    $project = Project::factory()->for(Tenant::factory())->create();
    $otherProject = Project::factory()->for(Tenant::factory())->create();

    DeviceProfile::factory()->for($project)->create(['code' => 'motor']);
    $otherProjectProfile = DeviceProfile::factory()->for($otherProject)->create(['code' => 'motor']);

    expect($otherProjectProfile->project()->firstOrFail()->getKey())->toBe($otherProject->getKey());
    expect(fn () => DeviceProfile::factory()->for($project)->create(['code' => 'motor']))
        ->toThrow(QueryException::class);
});

it('rejects duplicate versions within one profile', function (): void {
    $project = Project::factory()->for(Tenant::factory())->create();
    $profile = DeviceProfile::factory()->for($project)->create();

    DeviceProfileVersion::factory()->forProfile($profile)->create([
        'version' => 1,
        'schema_code' => 'motor-v1',
    ]);

    expect(fn () => DeviceProfileVersion::factory()->forProfile($profile)->create([
        'version' => 1,
        'schema_code' => 'motor-v1-copy',
    ]))->toThrow(QueryException::class);
});

it('rejects duplicate schema codes within one project', function (): void {
    $project = Project::factory()->for(Tenant::factory())->create();
    $profile = DeviceProfile::factory()->for($project)->create(['code' => 'motor']);
    $otherProfile = DeviceProfile::factory()->for($project)->create(['code' => 'pump']);

    DeviceProfileVersion::factory()->forProfile($profile)->create(['schema_code' => 'motor-v1']);

    expect(fn () => DeviceProfileVersion::factory()->forProfile($otherProfile)->create([
        'schema_code' => 'motor-v1',
    ]))->toThrow(QueryException::class);
});

it('rejects a profile version owned by another project', function (): void {
    $project = Project::factory()->for(Tenant::factory())->create();
    $otherProject = Project::factory()->for(Tenant::factory())->create();
    $profile = DeviceProfile::factory()->for($project)->create();

    expect(fn () => DeviceProfileVersion::factory()->forProfile($profile)->create([
        'project_id' => $otherProject->getKey(),
    ]))->toThrow(QueryException::class);
});

it('rejects assigning a profile version from another project to a device', function (): void {
    $project = Project::factory()->for(Tenant::factory())->create();
    $otherProject = Project::factory()->for(Tenant::factory())->create();
    $profile = DeviceProfile::factory()->for($project)->create();
    $version = DeviceProfileVersion::factory()->forProfile($profile)->create();
    $device = Device::factory()->for($otherProject)->create();

    $device->profileVersion()->associate($version);

    expect(fn () => $device->save())->toThrow(QueryException::class);
    expect(Device::query()->findOrFail($device->getKey())->getAttribute('device_profile_version_id'))
        ->toBeNull();
});

it('prevents deleting a profile version assigned to a device', function (): void {
    $project = Project::factory()->for(Tenant::factory())->create();
    $profile = DeviceProfile::factory()->for($project)->create();
    $version = DeviceProfileVersion::factory()->forProfile($profile)->create();
    $device = Device::factory()->for($project)->create();
    $device->profileVersion()->associate($version);
    $device->save();

    expect(fn () => $version->delete())->toThrow(QueryException::class);
    $this->assertModelExists($device->fresh());
});

it('cascades profile data when its owning tenant is deleted', function (): void {
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $profile = DeviceProfile::factory()->for($project)->create();
    $version = DeviceProfileVersion::factory()->forProfile($profile)->create();
    $device = Device::factory()->for($project)->create();
    $device->profileVersion()->associate($version);
    $device->save();

    $tenant->delete();

    $this->assertDatabaseMissing('device_profiles', ['id' => $profile->getKey()]);
    $this->assertDatabaseMissing('device_profile_versions', ['id' => $version->getKey()]);
    $this->assertDatabaseMissing('devices', ['id' => $device->getKey()]);
});

it('rejects non-positive profile versions', function (): void {
    $project = Project::factory()->for(Tenant::factory())->create();
    $profile = DeviceProfile::factory()->for($project)->create();

    expect(fn () => DeviceProfileVersion::factory()->forProfile($profile)->create([
        'version' => 0,
    ]))->toThrow(InvalidArgumentException::class);
});

it('does not allow an existing profile version to be changed', function (): void {
    $project = Project::factory()->for(Tenant::factory())->create();
    $profile = DeviceProfile::factory()->for($project)->create();
    $version = DeviceProfileVersion::factory()->forProfile($profile)->create();
    $definition = $version->definition;

    expect(fn () => $version->update([
        'definition' => ['strict_mode' => false, 'fields' => []],
    ]))->toThrow(LogicException::class, 'Device profile versions are immutable.');

    expect(DeviceProfileVersion::query()->findOrFail($version->getKey())->definition)
        ->toBe($definition);
});
