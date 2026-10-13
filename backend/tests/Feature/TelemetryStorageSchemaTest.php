<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Admin\Models\DeviceProfile\DeviceProfileVersion;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\Tenant\Tenant;

it('stores samples under their project and enforces device and profile project boundaries', function (): void {
    $project = Project::factory()->for(Tenant::factory())->create();
    $otherProject = Project::factory()->for(Tenant::factory())->create();
    $profile = DeviceProfile::factory()->for($project)->create();
    $otherProfile = DeviceProfile::factory()->for($otherProject)->create();
    $version = DeviceProfileVersion::factory()->forProfile($profile)->create();
    $otherVersion = DeviceProfileVersion::factory()->forProfile($otherProfile)->create();
    $device = Device::factory()->for($project)->create();
    $device->profileVersion()->associate($version);
    $device->save();

    $sampleId = (string) Str::uuid();
    DB::table('telemetry_samples')->insert([
        'id' => $sampleId,
        'project_id' => $project->getKey(),
        'device_id' => $device->getKey(),
        'device_profile_version_id' => $version->getKey(),
        'message_id' => 'message-1',
        'occurred_at' => '2026-10-11 04:00:00+00:00',
        'values' => json_encode(['temperature' => 40], JSON_THROW_ON_ERROR),
    ]);

    expect(Schema::hasColumn('telemetry_samples', 'tenant_id'))->toBeFalse();
    expect(fn () => DB::transaction(fn () => DB::table('telemetry_samples')->insert([
        'id' => (string) Str::uuid(),
        'project_id' => $otherProject->getKey(),
        'device_id' => $device->getKey(),
        'device_profile_version_id' => $otherVersion->getKey(),
        'message_id' => 'cross-project-device',
        'occurred_at' => '2026-10-11 04:00:00+00:00',
        'values' => json_encode(['temperature' => 41], JSON_THROW_ON_ERROR),
    ])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('telemetry_samples')->insert([
        'id' => (string) Str::uuid(),
        'project_id' => $project->getKey(),
        'device_id' => $device->getKey(),
        'device_profile_version_id' => $otherVersion->getKey(),
        'message_id' => 'cross-project-profile',
        'occurred_at' => '2026-10-11 04:00:00+00:00',
        'values' => json_encode(['temperature' => 42], JSON_THROW_ON_ERROR),
    ])))->toThrow(QueryException::class);

    $this->assertDatabaseHas('telemetry_samples', [
        'id' => $sampleId,
        'project_id' => $project->getKey(),
        'device_id' => $device->getKey(),
        'device_profile_version_id' => $version->getKey(),
        'message_id' => 'message-1',
        'status' => 'persisted',
    ]);
});

it('deduplicates a message id per device while allowing another device to use it', function (): void {
    $project = Project::factory()->for(Tenant::factory())->create();
    $profile = DeviceProfile::factory()->for($project)->create();
    $version = DeviceProfileVersion::factory()->forProfile($profile)->create();
    $firstDevice = Device::factory()->for($project)->create();
    $secondDevice = Device::factory()->for($project)->create();

    foreach ([$firstDevice, $secondDevice] as $index => $device) {
        $device->profileVersion()->associate($version);
        $device->save();

        DB::table('telemetry_samples')->insert([
            'id' => (string) Str::uuid(),
            'project_id' => $project->getKey(),
            'device_id' => $device->getKey(),
            'device_profile_version_id' => $version->getKey(),
            'message_id' => 'shared-message-id',
            'occurred_at' => '2026-10-11 04:00:00+00:00',
            'values' => json_encode(['temperature' => 40 + $index], JSON_THROW_ON_ERROR),
        ]);
    }

    expect(fn () => DB::transaction(fn () => DB::table('telemetry_samples')->insert([
        'id' => (string) Str::uuid(),
        'project_id' => $project->getKey(),
        'device_id' => $firstDevice->getKey(),
        'device_profile_version_id' => $version->getKey(),
        'message_id' => 'shared-message-id',
        'occurred_at' => '2026-10-11 04:00:00+00:00',
        'values' => json_encode(['temperature' => 44], JSON_THROW_ON_ERROR),
    ])))->toThrow(QueryException::class);

    expect(DB::table('telemetry_samples')->where('message_id', 'shared-message-id')->count())->toBe(2);
});

it('cascades device-owned samples, dedup identities, and outcomes when a device is deleted', function (): void {
    $project = Project::factory()->for(Tenant::factory())->create();
    $profile = DeviceProfile::factory()->for($project)->create();
    $version = DeviceProfileVersion::factory()->forProfile($profile)->create();
    $device = Device::factory()->for($project)->create();
    $device->profileVersion()->associate($version);
    $device->save();

    $messageId = 'delete-device-message';
    $sampleId = (string) Str::uuid();
    DB::table('telemetry_samples')->insert([
        'id' => $sampleId,
        'project_id' => $project->getKey(),
        'device_id' => $device->getKey(),
        'device_profile_version_id' => $version->getKey(),
        'message_id' => $messageId,
        'occurred_at' => '2026-10-11 04:00:00+00:00',
        'values' => json_encode(['temperature' => 40], JSON_THROW_ON_ERROR),
    ]);
    DB::table('telemetry_message_identities')->insert([
        'id' => (string) Str::uuid(),
        'project_id' => $project->getKey(),
        'device_id' => $device->getKey(),
        'message_id' => $messageId,
        'content_hash' => str_repeat('a', 64),
    ]);
    DB::table('telemetry_ingest_outcomes')->insert([
        'id' => (string) Str::uuid(),
        'project_id' => $project->getKey(),
        'device_id' => $device->getKey(),
        'message_id' => $messageId,
        'status' => 'persisted',
        'reason_codes' => json_encode([], JSON_THROW_ON_ERROR),
    ]);

    DB::table('devices')->where('id', $device->getKey())->delete();

    $this->assertDatabaseMissing('telemetry_samples', ['id' => $sampleId]);
    $this->assertDatabaseMissing('telemetry_message_identities', ['device_id' => $device->getKey()]);
    $this->assertDatabaseMissing('telemetry_ingest_outcomes', ['device_id' => $device->getKey()]);
    $this->assertDatabaseHas('device_profile_versions', ['id' => $version->getKey()]);
});
