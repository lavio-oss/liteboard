<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Admin\Enums\AlertRule\AlertRuleStatus;
use Modules\Admin\Models\AlertRule\AlertRule;
use Modules\Admin\Models\AlertRule\AlertRuleRevision;
use Modules\Admin\Models\DeviceProfile\DeviceProfile;

/** @extends Factory<AlertRule> */
final class AlertRuleFactory extends Factory
{
    protected $model = AlertRule::class;

    /** @return array{code: string, name: string, status: string} */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'name' => fake()->words(3, true),
            'status' => AlertRuleStatus::Active->value,
        ];
    }

    public function forProfile(DeviceProfile $profile): static
    {
        return $this->state([
            'project_id' => (string) $profile->getAttribute('project_id'),
            'device_profile_id' => (string) $profile->getKey(),
        ]);
    }

    /** Creates revision 1 and points the rule at it, like the persistence service does. */
    public function withRevision(): static
    {
        return $this->afterCreating(static function (AlertRule $rule): void {
            $revision = AlertRuleRevision::factory()->forRule($rule)->create();
            $rule->setAttribute('current_revision_id', $revision->getKey());
            $rule->save();
        });
    }
}
