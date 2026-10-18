<?php

namespace Modules\Admin\Requests\AlertRule;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Admin\Enums\AlertRule\AlertSeverity;
use Modules\Admin\Enums\AlertRule\ComparisonOperator;
use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Admin\Models\DeviceProfile\DeviceProfileVersion;

/**
 * Shared by rule creation and revision requests so both enforce the same evaluator contract.
 */
final class AlertRuleDefinitionRules
{
    public const MIN_DURATION_SECONDS = 5;

    public const MAX_DURATION_SECONDS = 3600;

    public const MIN_GAP_SECONDS = 5;

    public const MAX_GAP_SECONDS = 600;

    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        $condition = static fn (string $name): array => [
            "definition.$name" => ['required', 'array:operator,value,duration_seconds'],
            "definition.$name.operator" => ['required', Rule::enum(ComparisonOperator::class)],
            "definition.$name.value" => ['required', 'numeric'],
            "definition.$name.duration_seconds" => [
                'required', 'integer', 'min:'.self::MIN_DURATION_SECONDS, 'max:'.self::MAX_DURATION_SECONDS,
            ],
        ];

        return [
            'definition' => ['required', 'array:field,trigger,clear,max_sample_gap_seconds,severity'],
            'definition.field' => ['required', 'string', 'min:1', 'max:64'],
            ...$condition('trigger'),
            ...$condition('clear'),
            'definition.max_sample_gap_seconds' => [
                'required', 'integer', 'min:'.self::MIN_GAP_SECONDS, 'max:'.self::MAX_GAP_SECONDS,
                'lte:definition.trigger.duration_seconds',
            ],
            'definition.severity' => ['required', Rule::enum(AlertSeverity::class)],
        ];
    }

    /**
     * The evaluator reads JSON numbers only, so numeric strings accepted by validation are cast here.
     *
     * @param  array{field: string, trigger: array{operator: string, value: int|float|string, duration_seconds: int|string}, clear: array{operator: string, value: int|float|string, duration_seconds: int|string}, max_sample_gap_seconds: int|string, severity: string}  $definition
     * @return array{field: string, trigger: array{operator: string, value: int|float, duration_seconds: int}, clear: array{operator: string, value: int|float, duration_seconds: int}, max_sample_gap_seconds: int, severity: string}
     */
    public static function normalize(array $definition): array
    {
        /**
         * @param  array{operator: string, value: int|float|string, duration_seconds: int|string}  $raw
         * @return array{operator: string, value: int|float, duration_seconds: int}
         */
        $condition = static fn (array $raw): array => [
            'operator' => $raw['operator'],
            'value' => is_string($raw['value']) ? (float) $raw['value'] : $raw['value'],
            'duration_seconds' => (int) $raw['duration_seconds'],
        ];

        return [
            'field' => $definition['field'],
            'trigger' => $condition($definition['trigger']),
            'clear' => $condition($definition['clear']),
            'max_sample_gap_seconds' => (int) $definition['max_sample_gap_seconds'],
            'severity' => $definition['severity'],
        ];
    }

    /** Checks what the schema rules cannot: the profile field and the trigger/clear relationship. */
    public static function validateSemantics(Validator $validator, DeviceProfile $profile): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        /** @var array{field: string, trigger: array{operator: string, value: int|float|string}, clear: array{operator: string, value: int|float|string}} $definition */
        $definition = $validator->getData()['definition'];

        /** @var DeviceProfileVersion|null $latest */
        $latest = $profile->versions()->orderByDesc('version')->first();
        $type = $latest?->definition['fields'][$definition['field']]['type'] ?? null;

        if (! in_array($type, ['integer', 'float'], true)) {
            $validator->errors()->add('definition.field', 'The field must be a numeric field of the latest profile version.');
        }

        $trigger = ComparisonOperator::from($definition['trigger']['operator']);
        $clear = ComparisonOperator::from($definition['clear']['operator']);

        if ($trigger->isUpward() === $clear->isUpward()) {
            $validator->errors()->add('definition.clear.operator', 'The clear operator must point opposite to the trigger operator.');

            return;
        }

        $triggerValue = (float) $definition['trigger']['value'];
        $clearValue = (float) $definition['clear']['value'];
        [$lower, $upper] = $trigger->isUpward() ? [$triggerValue, $clearValue] : [$clearValue, $triggerValue];
        $overlaps = $trigger->isInclusive() && $clear->isInclusive() ? $upper >= $lower : $upper > $lower;

        if ($overlaps) {
            $validator->errors()->add('definition.clear.value', 'The clear range must not overlap the trigger range.');
        }
    }
}
