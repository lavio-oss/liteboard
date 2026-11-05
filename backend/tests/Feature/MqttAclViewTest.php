<?php

use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\Tenant\Tenant;

/**
 * @return list<array{string, int}>
 */
function aclRowsFor(Device $device): array
{
    return DB::table('v_mqtt_acls')
        ->where('token_hash', $device->credential()->value('token_hash'))
        ->orderBy('topic')
        ->orderBy('rw')
        ->get()
        ->map(fn (object $row): array => [(string) $row->topic, (int) $row->rw])
        ->all();
}

it('grants exactly the per-device ACL rows, with gateway topics only for gateways', function (): void {
    $project = Project::factory()->for(Tenant::factory()->create())->create();
    $plain = Device::factory()->for($project)->create();
    $gateway = Device::factory()->for($project)->gateway()->create();

    $base = "liteboard/$project->id/devices";

    expect(aclRowsFor($plain))->toBe([
        ["$base/$plain->id/commands", 1],
        ["$base/$plain->id/commands", 4],
        ["$base/$plain->id/commands/ack", 2],
        ["$base/$plain->id/heartbeat", 2],
        ["$base/$plain->id/telemetry", 2],
    ]);

    expect(aclRowsFor($gateway))->toBe([
        ["$base/$gateway->id/commands", 1],
        ["$base/$gateway->id/commands", 4],
        ["$base/$gateway->id/commands/ack", 2],
        ["$base/$gateway->id/gateway/connect", 2],
        ["$base/$gateway->id/gateway/disconnect", 2],
        ["$base/$gateway->id/gateway/telemetry", 2],
        ["$base/$gateway->id/heartbeat", 2],
        ["$base/$gateway->id/telemetry", 2],
    ]);

    expect(DB::table('v_mqtt_acls')->where('device_id', $plain->id)->pluck('topic')->every(
        fn (string $topic): bool => str_starts_with($topic, "$base/$plain->id/"),
    ))->toBeTrue();
});

it('grants no ACL rows unless credential, device, project and tenant are all active and approved', function (string $table, string $column, string $value): void {
    $tenant = Tenant::factory()->create();
    $project = Project::factory()->for($tenant)->create();
    $device = Device::factory()->for($project)->gateway()->create();

    expect(aclRowsFor($device))->toHaveCount(8);

    $key = match ($table) {
        'device_credentials' => ['device_id', $device->id],
        'devices' => ['id', $device->id],
        'projects' => ['id', $project->id],
        'tenants' => ['id', $tenant->id],
    };
    DB::table($table)->where($key[0], $key[1])->update([$column => $value]);

    expect(aclRowsFor($device))->toBe([]);
})->with([
    'credential revoked' => ['device_credentials', 'status', 'revoked'],
    'device inactive' => ['devices', 'status', 'inactive'],
    'device pending' => ['devices', 'approval_status', 'pending'],
    'device rejected' => ['devices', 'approval_status', 'rejected'],
    'project inactive' => ['projects', 'status', 'inactive'],
    'tenant inactive' => ['tenants', 'status', 'inactive'],
]);

it('does not leak ACL rows across tenants when another tenant is disabled', function (): void {
    $active = Device::factory()->for(Project::factory()->for(Tenant::factory()->create())->create())->create();
    $otherTenant = Tenant::factory()->create();
    $other = Device::factory()->for(Project::factory()->for($otherTenant)->create())->create();

    DB::table('tenants')->where('id', $otherTenant->id)->update(['status' => 'inactive']);

    expect(aclRowsFor($active))->toHaveCount(5)
        ->and(aclRowsFor($other))->toBe([]);
});
