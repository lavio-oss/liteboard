<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Admin\Models\AlertRule\AlertRule;
use Modules\Admin\Models\AlertRule\AlertRuleRevision;

/** @extends Factory<AlertRuleRevision> */
final class AlertRuleRevisionFactory extends Factory
{
    protected $model = AlertRuleRevision::class;

    /**
     * @return array{
     *     revision: int,
     *     definition: array{
     *         field: string,
     *         trigger: array{operator: string, value: int, duration_seconds: int},
     *         clear: array{operator: string, value: int, duration_seconds: int},
     *         max_sample_gap_seconds: int,
     *         severity: string
     *     }
     * }
     */
    public function definition(): array
    {
        return [
            'revision' => 1,
            'definition' => [
                'field' => 'temperature',
                'trigger' => ['operator' => 'gt', 'value' => 80, 'duration_seconds' => 30],
                'clear' => ['operator' => 'lte', 'value' => 75, 'duration_seconds' => 30],
                'max_sample_gap_seconds' => 15,
                'severity' => 'critical',
            ],
        ];
    }

    public function forRule(AlertRule $rule): static
    {
        return $this->state([
            'project_id' => (string) $rule->getAttribute('project_id'),
            'alert_rule_id' => (string) $rule->getKey(),
        ]);
    }
}
