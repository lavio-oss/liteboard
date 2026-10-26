<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Enums\Device\DeviceCredentialStatus;
use Modules\Admin\Enums\Device\DeviceCredentialType;
use Modules\Admin\Models\AlertRule\AlertRule;
use Modules\Admin\Models\Asset\Asset;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Admin\Models\DeviceProfile\DeviceProfileVersion;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Models\Webhook\WebhookEndpoint;
use Modules\Admin\Services\AlertRule\AlertRulePersistenceService;
use Modules\Admin\Services\Device\DeviceCredentialService;
use Modules\Admin\Services\Webhook\WebhookEndpointService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use RuntimeException;

/**
 * Demo profiles, devices, rules and webhooks for the Workflow screen and the ESP32 AccessToken sketch.
 * Idempotent: rows are matched by code/name and existing ones are left as they are.
 *
 * The ESP32 device can be pinned to the IDs and token already flashed on the board through
 * LITEBOARD_SEED_ESP32_PROJECT_ID / _DEVICE_ID / _ACCESS_TOKEN (config/demo_seed.php); otherwise new ones
 * are generated and printed.
 */
final class DemoWorkflowSeeder extends Seeder
{
    use WithoutModelEvents;

    private const array FIELDS = [
        'temperature' => ['type' => 'float', 'unit' => 'celsius', 'required' => true, 'min' => -40, 'max' => 150],
        'vibration' => ['type' => 'float', 'unit' => 'mm/s RMS', 'required' => true, 'min' => 0],
        'rpm' => ['type' => 'integer', 'unit' => 'rpm', 'required' => true, 'min' => 0],
        'load' => ['type' => 'float', 'unit' => 'percent', 'required' => false, 'min' => 0, 'max' => 100],
        'mode' => ['type' => 'string', 'unit' => 'state', 'required' => false, 'allowed_values' => ['idle', 'running', 'fault']],
        'pressure' => ['type' => 'float', 'unit' => 'bar', 'required' => true, 'min' => 0],
        'power' => ['type' => 'float', 'unit' => 'kW', 'required' => true, 'min' => 0],
        'humidity' => ['type' => 'float', 'unit' => 'percent', 'required' => true, 'min' => 0, 'max' => 100],
        'speed' => ['type' => 'float', 'unit' => 'm/min', 'required' => true, 'min' => 0],
        'current' => ['type' => 'float', 'unit' => 'A', 'required' => true, 'min' => 0],
        'printhead_temperature' => ['type' => 'float', 'unit' => 'celsius', 'required' => true],
        'labels_per_min' => ['type' => 'integer', 'unit' => 'labels/min', 'required' => true, 'min' => 0],
    ];

    public function __construct(
        private readonly DeviceCredentialService $credentials,
        private readonly AlertRulePersistenceService $rules,
        private readonly WebhookEndpointService $webhooks,
    ) {}

    public function run(): void
    {
        $tenant = Tenant::query()->where('code', 'demo')->firstOrFail();
        $admin = User::query()->where('email', 'admin@gmail.com')->firstOrFail();

        $this->seedProject(
            $this->project($tenant, 'Hanoi Assembly Operations'),
            $admin,
            profiles: [
                'cnc-spindle' => ['CNC Spindle Monitor', [['temperature', 'vibration', 'rpm'], ['temperature', 'vibration', 'rpm', 'load', 'mode']]],
                'air-compressor' => ['Air Compressor', [['pressure', 'temperature', 'power']]],
                'env-sensor' => ['Environment Sensor', [['temperature', 'humidity']]],
            ],
            devices: [
                ['CNC Spindle 01', 'cnc-spindle-01', 'cnc-spindle', 2, 'assembly-program-cnc-turning-center-01'],
                ['CNC Spindle 02', 'cnc-spindle-02', 'cnc-spindle', 2, null],
                ['CNC Spindle 03', 'cnc-spindle-03', 'cnc-spindle', 1, null],
                ['CNC Spindle 04', 'cnc-spindle-04', 'cnc-spindle', 1, null],
                ['Robot Arm Drive 01', 'robot-arm-drive-01', 'cnc-spindle', 2, 'assembly-program-robotic-loading-arm-01'],
                ['Air Compressor 01', 'air-compressor-01', 'air-compressor', 1, 'assembly-program-air-compressor-01'],
                ['Air Compressor 02', 'air-compressor-02', 'air-compressor', 1, null],
                ['Air Dryer Sensor 01', 'air-dryer-sensor-01', 'env-sensor', 1, 'assembly-program-refrigerated-air-dryer-01'],
                ['Building A Climate 01', 'bldg-a-climate-01', 'env-sensor', 1, null],
                ['Building A Climate 02', 'bldg-a-climate-02', 'env-sensor', 1, null],
                ['Building B Climate 01', 'bldg-b-climate-01', 'env-sensor', 1, null],
                ['Stator Winder Monitor', 'stator-winder-monitor', 'cnc-spindle', 1, 'assembly-program-stator-winding-machine-01'],
                ['Spare Gateway 01', 'spare-gateway-01', null, null, null],
                ['Spare Gateway 02', 'spare-gateway-02', null, null, null],
            ],
            rules: [
                ['spindle-overheat', 'Spindle overheat', 'cnc-spindle', ['temperature', 'gt', 85, 30, 'lte', 75, 30, 15, 'critical']],
                ['spindle-vibration', 'Spindle vibration high', 'cnc-spindle', ['vibration', 'gt', 7.1, 20, 'lte', 4.5, 20, 10, 'major']],
                ['spindle-underspeed', 'Spindle under speed', 'cnc-spindle', ['rpm', 'lt', 500, 60, 'gte', 800, 30, 30, 'warning']],
                ['compressor-pressure-low', 'Compressor pressure low', 'air-compressor', ['pressure', 'lt', 6, 30, 'gte', 6.5, 30, 15, 'major']],
                ['compressor-hot', 'Compressor hot', 'air-compressor', ['temperature', 'gt', 95, 30, 'lte', 85, 30, 15, 'critical']],
                ['climate-humid', 'Climate humidity high', 'env-sensor', ['humidity', 'gt', 70, 60, 'lte', 60, 60, 30, 'minor']],
            ],
            webhooks: [
                ['Ops channel (all rules)', 'https://example.com/liteboard/hanoi/ops', null, true],
                ['On-call pager (critical)', 'https://example.com/liteboard/hanoi/pager', ['spindle-overheat', 'compressor-hot'], true],
                ['Legacy EAM (disabled)', 'https://example.com/liteboard/hanoi/eam', ['spindle-underspeed'], false],
            ],
        );

        $this->seedProject(
            $this->project($tenant, 'Da Nang Packaging Operations'),
            $admin,
            profiles: [
                'conveyor-drive' => ['Conveyor Drive', [['speed', 'current', 'temperature']]],
                'label-printer' => ['Label Printer', [['printhead_temperature', 'labels_per_min'], ['printhead_temperature', 'labels_per_min', 'mode']]],
                'env-sensor' => ['Environment Sensor', [['temperature', 'humidity']]],
            ],
            devices: [
                ['Cartoning Conveyor 01', 'cartoning-conveyor-01', 'conveyor-drive', 1, 'packaging-program-carton-former-01'],
                ['Cartoning Conveyor 02', 'cartoning-conveyor-02', 'conveyor-drive', 1, null],
                ['Palletizing Conveyor 01', 'palletizing-conveyor-01', 'conveyor-drive', 1, 'packaging-program-palletizing-robot-01'],
                ['Dispatch Conveyor 01', 'dispatch-conveyor-01', 'conveyor-drive', 1, null],
                ['Label Printer 01', 'label-printer-01', 'label-printer', 2, 'packaging-program-industrial-label-printer-01'],
                ['Label Printer 02', 'label-printer-02', 'label-printer', 1, null],
                ['Warehouse Climate 01', 'warehouse-climate-01', 'env-sensor', 1, null],
                ['Warehouse Climate 02', 'warehouse-climate-02', 'env-sensor', 1, null],
                ['Cold Room Sensor 01', 'cold-room-sensor-01', 'env-sensor', 1, null],
                ['Spare Gateway 01', 'spare-gateway-01', null, null, null],
            ],
            rules: [
                ['conveyor-overcurrent', 'Conveyor overcurrent', 'conveyor-drive', ['current', 'gt', 12, 10, 'lte', 10, 20, 10, 'major']],
                ['conveyor-stalled', 'Conveyor stalled', 'conveyor-drive', ['speed', 'lt', 5, 30, 'gte', 10, 15, 15, 'critical']],
                ['printhead-hot', 'Printhead hot', 'label-printer', ['printhead_temperature', 'gt', 70, 20, 'lte', 60, 20, 10, 'warning']],
                ['warehouse-humid', 'Warehouse humidity high', 'env-sensor', ['humidity', 'gt', 75, 60, 'lte', 65, 60, 30, 'minor']],
            ],
            webhooks: [
                ['Warehouse ops (all rules)', 'https://example.com/liteboard/danang/ops', null, true],
                ['Maintenance (conveyors)', 'https://example.com/liteboard/danang/maintenance', ['conveyor-overcurrent', 'conveyor-stalled'], true],
            ],
        );

        $this->seedEsp32Lab($tenant, $admin);
        $this->call(DemoMembersSeeder::class);
    }

    private function project(Tenant $tenant, string $name): Project
    {
        return Project::query()->where('tenant_id', $tenant->getKey())->where('name', $name)->firstOrFail();
    }

    /**
     * The sketch publishes only `temperature` with schema_version 1, so its device sits on v1 of a
     * temperature-only profile. Its over-temperature spikes are single samples, so the 5s minimum
     * rule duration never opens "ESP32 overheat"; "ESP32 warm" follows the random walk and does open.
     */
    private function seedEsp32Lab(Tenant $tenant, User $admin): void
    {
        $projectId = config('demo_seed.esp32.project_id');
        $deviceId = config('demo_seed.esp32.device_id');
        $token = config('demo_seed.esp32.access_token');

        $project = Project::query()->where('tenant_id', $tenant->getKey())->where('name', 'ESP32 Lab')->first();

        if ($project === null) {
            $project = new Project;
            $project->forceFill([
                ...(is_string($projectId) ? ['id' => $projectId] : []),
                'tenant_id' => $tenant->getKey(),
                'name' => 'ESP32 Lab',
                'description' => 'Bench devices running the ESP32 AccessToken sketch.',
                'status' => 'active',
            ]);
            $project->save();
        }

        $this->seedProject(
            $project,
            $admin,
            profiles: [
                'esp32-thermometer' => ['ESP32 Thermometer', [['temperature']]],
            ],
            devices: [
                ['ESP32 Bench 01', 'esp32-bench-01', 'esp32-thermometer', 1, null, is_string($deviceId) ? $deviceId : null, is_string($token) ? $token : null],
                ['ESP32 Bench 02', 'esp32-bench-02', 'esp32-thermometer', 1, null],
                ['ESP32 Bench 03', 'esp32-bench-03', 'esp32-thermometer', 1, null],
            ],
            rules: [
                ['esp32-overheat', 'ESP32 overheat', 'esp32-thermometer', ['temperature', 'gt', 100, 5, 'lte', 30, 5, 5, 'critical']],
                ['esp32-warm', 'ESP32 warm', 'esp32-thermometer', ['temperature', 'gt', 26, 10, 'lte', 25, 10, 10, 'minor']],
            ],
            webhooks: [
                ['Bench log (all rules)', 'https://example.com/liteboard/esp32/log', null, true],
                ['Bench overheat only', 'https://example.com/liteboard/esp32/overheat', ['esp32-overheat'], true],
            ],
        );

        $device = Device::query()->where('project_id', $project->getKey())->where('code', 'esp32-bench-01')->firstOrFail();
        $this->command->info('ESP32 Bench 01');
        $this->command->line("  LB_PROJECT_ID      = {$project->getKey()}");
        $this->command->line("  LB_DEVICE_ID       = {$device->getKey()}");
        $this->command->line("  LB_TELEMETRY_TOPIC = liteboard/{$project->getKey()}/devices/{$device->getKey()}/telemetry");
        $this->command->line('  LB_ACCESS_TOKEN    = '.(is_string($token) ? '(from LITEBOARD_SEED_ESP32_ACCESS_TOKEN)' : $device->credential()->firstOrFail()->encrypted_token));
    }

    /**
     * @param  array<string, array{0: string, 1: list<list<string>>}>  $profiles  code => [name, versions as field lists]
     * @param  list<array{0: string, 1: string, 2: string|null, 3: int|null, 4: string|null, 5?: string|null, 6?: string|null}>  $devices  [name, code, profile, version, equipment asset code, pinned id, pinned token]
     * @param  list<array{0: string, 1: string, 2: string, 3: array{0: string, 1: string, 2: int|float, 3: int, 4: string, 5: int|float, 6: int, 7: int, 8: string}}>  $rules
     * @param  list<array{0: string, 1: string, 2: list<string>|null, 3: bool}>  $webhooks  [name, url, rule codes or null for every seeded rule, enabled]
     */
    private function seedProject(Project $project, User $admin, array $profiles, array $devices, array $rules, array $webhooks): void
    {
        DB::transaction(function () use ($project, $admin, $profiles, $devices, $rules, $webhooks): void {
            /** @var array<string, array{profile: DeviceProfile, versions: array<int, DeviceProfileVersion>}> $seeded */
            $seeded = [];

            foreach ($profiles as $code => [$name, $versions]) {
                $profile = DeviceProfile::query()->firstOrNew(['project_id' => $project->getKey(), 'code' => $code]);
                $profile->forceFill(['project_id' => $project->getKey(), 'code' => $code, 'name' => $name, 'description' => "Demo profile: $name."])->save();
                $seeded[$code] = ['profile' => $profile, 'versions' => []];

                foreach ($versions as $index => $fields) {
                    $number = $index + 1;
                    $version = DeviceProfileVersion::query()->firstOrNew(['device_profile_id' => $profile->getKey(), 'version' => $number]);

                    if (! $version->exists) {
                        $version->forceFill([
                            'project_id' => $project->getKey(),
                            'device_profile_id' => $profile->getKey(),
                            'version' => $number,
                            'schema_code' => "$code-v$number",
                            'definition' => [
                                'strict_mode' => true,
                                'fields' => array_intersect_key(self::FIELDS, array_flip($fields)),
                            ],
                            'compatibility_note' => $number > 1 ? 'Adds optional fields.' : null,
                        ])->save();
                    }

                    $seeded[$code]['versions'][$number] = $version;
                }
            }

            foreach ($devices as $device) {
                [$name, $code, $profileCode, $versionNumber, $assetCode] = $device;
                $pinnedId = $device[5] ?? null;
                $pinnedToken = $device[6] ?? null;

                if (Device::query()->where('project_id', $project->getKey())->where('code', $code)->exists()) {
                    continue;
                }

                $model = new Device;
                $model->forceFill([
                    ...($pinnedId === null ? [] : ['id' => $pinnedId]),
                    'project_id' => $project->getKey(),
                    'name' => $name,
                    'code' => $code,
                    'description' => 'Seeded demo device.',
                    'device_profile_version_id' => $profileCode === null || $versionNumber === null
                        ? null
                        : $seeded[$profileCode]['versions'][$versionNumber]->getKey(),
                    'equipment_asset_id' => $assetCode === null
                        ? null
                        : Asset::query()->where('project_id', $project->getKey())->where('code', $assetCode)->firstOrFail()->getKey(),
                ])->save();

                if ($pinnedToken === null) {
                    $this->credentials->issueForDevice($model);
                } else {
                    $model->credential()->create([
                        'credential_type' => DeviceCredentialType::AccessToken->value,
                        'token_hash' => $this->credentials->hashToken($pinnedToken),
                        'mqtt_password_hash' => $this->credentials->hashMqttPassword($pinnedToken),
                        'encrypted_token' => $pinnedToken,
                        'status' => DeviceCredentialStatus::Active->value,
                        'rotated_at' => CarbonImmutable::now(),
                        'revoked_at' => null,
                    ]);
                }
            }

            /** @var array<string, string> $ruleIds */
            $ruleIds = [];

            foreach ($rules as [$code, $name, $profileCode, [$field, $triggerOperator, $triggerValue, $triggerDuration, $clearOperator, $clearValue, $clearDuration, $gap, $severity]]) {
                $rule = AlertRule::query()->where('project_id', $project->getKey())->where('code', $code)->first()
                    ?? $this->rules->create($project, [
                        'code' => $code,
                        'name' => $name,
                        'device_profile_id' => (string) $seeded[$profileCode]['profile']->getKey(),
                    ], [
                        'field' => $field,
                        'trigger' => ['operator' => $triggerOperator, 'value' => $triggerValue, 'duration_seconds' => $triggerDuration],
                        'clear' => ['operator' => $clearOperator, 'value' => $clearValue, 'duration_seconds' => $clearDuration],
                        'max_sample_gap_seconds' => $gap,
                        'severity' => $severity,
                    ], $admin);
                $ruleIds[$code] = (string) $rule->getKey();
            }

            foreach ($webhooks as [$name, $url, $ruleCodes, $enabled]) {
                if (WebhookEndpoint::query()->where('project_id', $project->getKey())->where('name', $name)->exists()) {
                    continue;
                }

                $this->webhooks->create($project, [
                    'name' => $name,
                    'url' => $url,
                    'event_types' => ['alert.opened', 'alert.cleared', 'alert.acknowledged', 'alert.severity_changed'],
                    'alert_rule_ids' => $ruleCodes === null
                        ? array_values($ruleIds)
                        : array_map(static fn (string $code): string => $ruleIds[$code] ?? throw new RuntimeException("Unknown rule $code"), $ruleCodes),
                    'enabled' => $enabled,
                ]);
            }
        });
    }
}
