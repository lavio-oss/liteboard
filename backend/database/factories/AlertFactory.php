<?php

namespace Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Admin\Enums\AlertRule\AlertSeverity;
use Modules\Admin\Models\Alert\Alert;
use Modules\Admin\Models\AlertRule\AlertRule;
use Modules\Admin\Models\Device\Device;

/** @extends Factory<Alert> */
final class AlertFactory extends Factory
{
    protected $model = Alert::class;

    /**
     * @return array{
     *     severity: string,
     *     opened_at: CarbonImmutable,
     *     last_seen_at: CarbonImmutable,
     *     evidence: array{field: string, value: float}
     * }
     */
    public function definition(): array
    {
        $openedAt = now()->toImmutable()->subMinutes(5);

        return [
            'severity' => AlertSeverity::Critical->value,
            'opened_at' => $openedAt,
            'last_seen_at' => $openedAt,
            'evidence' => ['field' => 'temperature', 'value' => 85.0],
        ];
    }

    public function forRuleAndDevice(AlertRule $rule, Device $device): static
    {
        return $this->state([
            'project_id' => (string) $rule->getAttribute('project_id'),
            'alert_rule_id' => (string) $rule->getKey(),
            'alert_rule_revision_id' => (string) $rule->getAttribute('current_revision_id'),
            'device_id' => (string) $device->getKey(),
            'equipment_asset_id' => $device->getAttribute('equipment_asset_id'),
            'device_profile_version_id' => $device->getAttribute('device_profile_version_id'),
        ]);
    }

    public function cleared(string $reason = 'condition_cleared'): static
    {
        return $this->state([
            'cleared_at' => now()->toImmutable()->subMinute(),
            'clear_reason' => $reason,
        ]);
    }
}
